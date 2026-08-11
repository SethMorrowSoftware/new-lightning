<?php
/**
 * Slack delivery.
 *
 * Two modes:
 *  - bot   — a bot token (xoxb-…) posting via chat.postMessage. Preferred:
 *            one token can post to any channel the bot is in, and Slack tells
 *            us precisely why a post failed.
 *  - webhook — an incoming webhook URL, for workspaces where creating an app
 *            is not practical. The channel is fixed by the webhook itself.
 */
declare(strict_types=1);

namespace StormWatch\Notifiers;

use StormWatch\Alert;
use StormWatch\Geo;
use StormWatch\Http;
use StormWatch\Settings;

final class SlackNotifier implements NotifierInterface
{
    private const API_URL = 'https://slack.com/api/chat.postMessage';
    private const TIMEOUT = 15;

    /** Slack API error codes translated into something an operator can act on. */
    private const ERROR_HINTS = [
        'invalid_auth' => 'Slack rejected the bot token. Copy it again from OAuth & Permissions — it should start with "xoxb-".',
        'not_authed' => 'No bot token was sent. Save a token in the Slack settings first.',
        'account_inactive' => 'That token belongs to a deactivated app or workspace.',
        'token_revoked' => 'That token has been revoked. Reinstall the app to your workspace and copy the new token.',
        'channel_not_found' => 'Slack could not find that channel. Use the channel ID (Slack: right-click the channel → View channel details) or the exact #name.',
        'not_in_channel' => 'The bot is not a member of that channel. In Slack, type /invite @YourBotName in the channel.',
        'is_archived' => 'That channel is archived. Pick an active one.',
        'missing_scope' => 'The app is missing the chat:write scope. Add it under OAuth & Permissions, then reinstall the app.',
        'no_permission' => 'The token does not have permission to post there. Check the app scopes.',
        // Slack sends this one as a single word; the underscored spelling never
        // matched anything, so the operator got the raw code instead of the hint.
        'ratelimited' => 'Slack is rate limiting this app. Alerts will resume shortly.',
        'rate_limited' => 'Slack is rate limiting this app. Alerts will resume shortly.',
        'invalid_blocks' => 'Slack rejected the message layout. This is a bug — please report it.',
        'restricted_action' => 'Workspace settings prevent this app from posting to that channel.',
    ];

    public static function channel(): string
    {
        return 'slack';
    }

    public static function isEnabled(): bool
    {
        if (!Settings::getBool('slack_enabled')) {
            return false;
        }
        if (Settings::getString('slack_mode') === 'webhook') {
            return Settings::getString('slack_webhook_url') !== '';
        }
        return Settings::getString('slack_bot_token') !== '' && trim(Settings::getString('slack_channel')) !== '';
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function send(Alert $alert): array
    {
        if (Settings::getString('slack_mode') === 'webhook') {
            return self::sendViaWebhook($alert);
        }
        return self::sendViaBot($alert);
    }

    /**
     * Post a test message, bypassing the "enabled" check so an operator can
     * verify a token before switching alerts on.
     *
     * @return array{ok:bool,message:string}
     */
    public static function sendTest(): array
    {
        $alert = new Alert(
            Alert::KIND_TEST,
            'Storm Watch test alert',
            sprintf(
                'This is a test from the Storm Watch dashboard for %s. If you can read this, lightning alerts will reach this channel.',
                Settings::getString('venue_name')
            )
        );
        $alert->details['Alert radius'] = \StormWatch\Geo::formatDistance(
            Settings::getFloat('alert_radius_mi'),
            Settings::getString('units')
        );
        $alert->details['Sent'] = $alert->localTime();

        if (Settings::getString('slack_mode') === 'webhook') {
            if (Settings::getString('slack_webhook_url') === '') {
                return ['ok' => false, 'message' => 'Save an incoming webhook URL first.'];
            }
            return self::sendViaWebhook($alert);
        }

        if (Settings::getString('slack_bot_token') === '') {
            return ['ok' => false, 'message' => 'Save a bot token first.'];
        }
        if (trim(Settings::getString('slack_channel')) === '') {
            return ['ok' => false, 'message' => 'Enter the channel to post to first.'];
        }
        return self::sendViaBot($alert);
    }

    /**
     * Post a sample warning so the operator can see their own wording and
     * colours in the channel before a storm does it for real. The sample is
     * marked as a preview in the notification line and in a trailing context
     * row — this lands in the live alerts channel, and a message that looks
     * exactly like a warning had better say it is not one. Mentions are
     * suppressed for the same reason.
     *
     * @return array{ok:bool,message:string}
     */
    public static function sendPreview(): array
    {
        $units = Settings::getString('units');
        $radius = Settings::getFloat('alert_radius_mi');
        $radiusText = Geo::formatDistance($radius, $units);

        // The same wording AlertEngine::buildWarning() uses, so a blank
        // template previews exactly what would be sent.
        $alert = new Alert(
            Alert::KIND_WARNING,
            sprintf('Lightning within %s of %s', $radiusText, Settings::getString('venue_name')),
            sprintf(
                'A strike has been detected inside the %s alert radius. Move activities indoors and hold until the all clear.',
                $radiusText
            )
        );
        $alert->nearestMi = round(max(0.5, $radius * 0.4), 1);
        $alert->bearingDeg = 315.0;
        $alert->struckAt = time();
        $alert->strikeCount = 4;
        $alert->details['All clear after'] = sprintf(
            '%d min with no strike inside %s',
            Settings::getInt('all_clear_minutes'),
            $radiusText
        );
        $alert->suppressMentions = true;

        $payload = self::messagePayload($alert);
        $payload['text'] = self::truncate('Preview: ' . $payload['text'], 3000);
        $payload['attachments'][0]['blocks'][] = [
            'type' => 'context',
            'elements' => [['type' => 'mrkdwn', 'text' => 'Preview — sample values, not a real strike.']],
        ];

        if (Settings::getString('slack_mode') === 'webhook') {
            if (Settings::getString('slack_webhook_url') === '') {
                return ['ok' => false, 'message' => 'Save an incoming webhook URL first.'];
            }
            return self::postWebhook($payload);
        }
        if (Settings::getString('slack_bot_token') === '') {
            return ['ok' => false, 'message' => 'Save a bot token first.'];
        }
        if (trim(Settings::getString('slack_channel')) === '') {
            return ['ok' => false, 'message' => 'Enter the channel to post to first.'];
        }
        return self::postBot($payload);
    }

    /** @return array{ok:bool,message:string} */
    private static function sendViaBot(Alert $alert): array
    {
        return self::postBot(self::messagePayload($alert));
    }

    /** @return array{ok:bool,message:string} */
    private static function postBot(array $payload): array
    {
        $token = Settings::getString('slack_bot_token');
        $channel = trim(Settings::getString('slack_channel'));

        $payload['channel'] = $channel;
        if (Settings::getString('slack_username') !== '') {
            $payload['username'] = Settings::getString('slack_username');
        }
        if (Settings::getString('slack_icon_emoji') !== '') {
            $payload['icon_emoji'] = self::normaliseEmoji(Settings::getString('slack_icon_emoji'));
        }

        [$body, $status, $error] = self::post(self::API_URL, json_encode($payload), [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $token,
        ]);

        if ($error !== null) {
            return ['ok' => false, 'message' => 'Could not reach Slack: ' . $error];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => sprintf('Slack returned an unexpected response (HTTP %d).', $status)];
        }
        if (!empty($decoded['ok'])) {
            return ['ok' => true, 'message' => sprintf('Posted to %s.', $channel)];
        }

        $code = (string) ($decoded['error'] ?? 'unknown_error');
        $hint = self::ERROR_HINTS[$code] ?? null;
        return [
            'ok' => false,
            'message' => $hint !== null ? $hint . ' (Slack said: ' . $code . ')' : 'Slack rejected the message: ' . $code,
        ];
    }

    /** @return array{ok:bool,message:string} */
    private static function sendViaWebhook(Alert $alert): array
    {
        return self::postWebhook(self::messagePayload($alert));
    }

    /** @return array{ok:bool,message:string} */
    private static function postWebhook(array $payload): array
    {
        $url = Settings::getString('slack_webhook_url');
        if (!preg_match('#^https://hooks\.slack\.com/#i', $url)) {
            return ['ok' => false, 'message' => 'That does not look like a Slack incoming webhook URL.'];
        }

        [$body, $status, $error] = self::post($url, json_encode($payload), [
            'Content-Type: application/json; charset=utf-8',
        ]);

        if ($error !== null) {
            return ['ok' => false, 'message' => 'Could not reach Slack: ' . $error];
        }
        if ($status === 200 && strtolower(trim($body)) === 'ok') {
            return ['ok' => true, 'message' => 'Posted via the incoming webhook.'];
        }
        if ($status === 404) {
            return ['ok' => false, 'message' => 'Slack returned 404 — that webhook URL no longer exists.'];
        }
        return [
            'ok' => false,
            'message' => sprintf('Slack rejected the webhook post (HTTP %d): %s', $status, trim($body) ?: 'no detail given'),
        ];
    }

    /**
     * Build the Block Kit message shared by both modes.
     *
     * The wording and look are the operator's to change: each alert kind has
     * an optional headline and message template (blank means the standard
     * wording built by the engine), the accent colour per kind is a setting,
     * and the layout can drop to a compact single paragraph. All of it is
     * Slack-only — email keeps the standard text, so the channel someone
     * styles for a busy workspace cannot quietly rewrite the record that
     * lands in inboxes.
     *
     * @return array<string,mixed>
     */
    public static function messagePayload(Alert $alert): array
    {
        $mention = self::mentionPrefix($alert);
        $title = self::resolvedTitle($alert);
        $summaryText = self::resolvedSummary($alert);

        // The mention is deliberate Slack markup and must survive as-is;
        // everything else is text and gets escaped. Escaping only &, < and >
        // leaves Slack's own *bold* and _italic_ working in custom text.
        $summary = ($mention !== '' ? $mention . ' ' : '') . self::escape($summaryText);

        if (Settings::getString('slack_style') === 'compact') {
            // One paragraph, no detail rows, no footer: for the workspace
            // where the full card reads as noise. The colour bar and the
            // notification line still carry the severity.
            $blocks = [[
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => self::truncate('*' . self::escape($title) . "*\n" . $summary, 2900),
                ],
            ]];
        } else {
            $blocks = [
                [
                    // A plain_text block is rendered literally, so it takes the
                    // unescaped title — escaping here would show "&amp;" to users.
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => self::truncate($title, 150),
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => self::truncate($summary, 2900)],
                ],
            ];

            $fields = [];
            foreach ($alert->detailRows() as $label => $value) {
                $fields[] = [
                    'type' => 'mrkdwn',
                    'text' => sprintf("*%s*\n%s", self::escape($label), self::escape($value)),
                ];
            }
            // Slack allows at most 10 fields in one section.
            foreach (array_chunk($fields, 10) as $chunk) {
                $blocks[] = ['type' => 'section', 'fields' => $chunk];
            }

            $contextParts = [sprintf('*%s*', self::escape(Settings::getString('venue_name')))];
            if (Settings::getString('venue_address') !== '') {
                $contextParts[] = self::escape(Settings::getString('venue_address'));
            }
            $dashboardUrl = self::dashboardUrl();
            if ($dashboardUrl !== null) {
                $contextParts[] = sprintf('<%s|Open the dashboard>', $dashboardUrl);
            }
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => implode('  ·  ', $contextParts)]],
            ];
        }

        // The fallback is built from the resolved text, not Alert::plainText():
        // a device that cannot render blocks should still see the operator's
        // wording, not the standard wording it replaced.
        $fallbackLines = [$title, '', $summaryText];
        foreach ($alert->detailRows() as $label => $value) {
            $fallbackLines[] = $label . ': ' . $value;
        }

        return [
            // Plain text is what Slack shows in notifications and on devices
            // that cannot render blocks, so it has to stand on its own.
            'text' => self::truncate(
                ($mention !== '' ? $mention . ' ' : '') . self::escape($title),
                3000
            ),
            'attachments' => [[
                'color' => self::colourFor($alert),
                'blocks' => $blocks,
                'fallback' => self::truncate(self::escape(implode("\n", $fallbackLines)), 3000),
            ]],
        ];
    }

    /**
     * Fill an operator template's placeholders from the alert. Unknown
     * placeholders are left visible rather than swallowed — a typo the
     * preview shows is a typo that gets fixed.
     */
    public static function renderTemplate(string $template, Alert $alert): string
    {
        $units = Settings::getString('units');
        return strtr($template, [
            '{venue}' => Settings::getString('venue_name'),
            '{venue_address}' => Settings::getString('venue_address'),
            '{distance}' => $alert->distanceText(),
            '{direction}' => $alert->bearingDeg !== null ? Geo::compassPoint($alert->bearingDeg) : '—',
            '{time}' => $alert->localTime(),
            '{alert_radius}' => Geo::formatDistance(Settings::getFloat('alert_radius_mi'), $units),
            '{watch_radius}' => Geo::formatDistance(Settings::getFloat('watch_radius_mi'), $units),
            '{cooldown_minutes}' => (string) Settings::getInt('all_clear_minutes'),
            '{strikes}' => (string) $alert->strikeCount,
        ]);
    }

    /**
     * The stored template for this alert kind and part, or null for "use the
     * standard wording". Updates have their own slot rather than borrowing
     * the warning's: their standard text says what changed, and a warning
     * template pasted over it would announce the storm as new every time.
     */
    private static function template(Alert $alert, string $part): ?string
    {
        static $kinds = [
            Alert::KIND_WARNING => 'warning',
            Alert::KIND_WATCH => 'watch',
            Alert::KIND_ALL_CLEAR => 'all_clear',
            Alert::KIND_UPDATE => 'update',
        ];
        if (!isset($kinds[$alert->kind])) {
            return null; // tests and error notices always use the built-in text
        }
        $stored = trim(Settings::getString('slack_tpl_' . $kinds[$alert->kind] . '_' . $part));
        return $stored === '' ? null : $stored;
    }

    /**
     * A custom headline is used exactly as written — no emoji is prepended,
     * so an operator who wants one puts it in the template, and one who
     * wants none is not stuck with ours.
     */
    private static function resolvedTitle(Alert $alert): string
    {
        $template = self::template($alert, 'title');
        if ($template !== null) {
            return self::renderTemplate($template, $alert);
        }
        return $alert->emoji() . ' ' . $alert->title;
    }

    private static function resolvedSummary(Alert $alert): string
    {
        $template = self::template($alert, 'body');
        if ($template !== null) {
            return self::renderTemplate($template, $alert);
        }
        return $alert->summary;
    }

    /** The attachment bar colour: the operator's, or the built-in per kind. */
    private static function colourFor(Alert $alert): string
    {
        switch ($alert->kind) {
            case Alert::KIND_WARNING:
            case Alert::KIND_UPDATE:
                $key = 'slack_color_warning';
                break;
            case Alert::KIND_WATCH:
                $key = 'slack_color_watch';
                break;
            case Alert::KIND_ALL_CLEAR:
                $key = 'slack_color_all_clear';
                break;
            default:
                return $alert->colour();
        }
        $colour = trim(Settings::getString($key));
        // Validation keeps bad values out of the store, but a colour that
        // somehow is not a colour must degrade to the standard one, not to a
        // Slack API error during a storm.
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $colour) === 1 ? $colour : $alert->colour();
    }

    private static function mentionPrefix(Alert $alert): string
    {
        // A preview posts to the live alerts channel; it must never page it.
        if ($alert->suppressMentions) {
            return '';
        }
        $parts = [];
        // Only escalate the room for alerts people need to act on.
        if ($alert->isCritical()) {
            $mention = Settings::getString('slack_mention');
            if ($mention === 'here') {
                $parts[] = '<!here>';
            } elseif ($mention === 'channel') {
                $parts[] = '<!channel>';
            }
        }
        $extra = trim(Settings::getString('slack_extra_mention'));
        if ($extra !== '' && $alert->isCritical()) {
            $parts[] = $extra;
        }
        return implode(' ', $parts);
    }

    private static function dashboardUrl(): ?string
    {
        try {
            $url = Http::absoluteUrl('index.php');
        } catch (\Throwable $e) {
            return null;
        }
        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    private static function normaliseEmoji(string $emoji): string
    {
        $emoji = trim($emoji);
        if ($emoji === '') {
            return '';
        }
        return ':' . trim($emoji, ':') . ':';
    }

    /** Slack mrkdwn needs these three characters escaped. */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private static function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : (mb_substr($text, 0, $limit - 1) . '…');
    }

    /**
     * @param array<int,string> $headers
     * @return array{0:string,1:int,2:string|null}
     */
    private static function post(string $url, string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'StormWatch/' . SW_VERSION,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
            curl_close($ch);
            return [is_string($response) ? $response : '', $status, $error];
        }

        if (!ini_get('allow_url_fopen')) {
            return ['', 0, 'This server has neither cURL nor allow_url_fopen, so it cannot reach the Slack API.'];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($response === false) {
            return ['', $status, 'The request to Slack could not be completed.'];
        }
        return [$response, $status, null];
    }
}
