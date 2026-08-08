<?php
/**
 * Email delivery. Renders the alert as a short, scannable message — the kind
 * someone can read on a phone in ten seconds while standing on a wet midway.
 */
declare(strict_types=1);

namespace StormWatch\Notifiers;

use StormWatch\Alert;
use StormWatch\Http;
use StormWatch\Settings;

final class EmailNotifier implements NotifierInterface
{
    public static function channel(): string
    {
        return 'email';
    }

    public static function isEnabled(): bool
    {
        return Settings::getBool('email_enabled')
            && Settings::getList('email_to') !== []
            && trim(Settings::getString('email_from')) !== '';
    }

    /** @return array{ok:bool,message:string} */
    public static function send(Alert $alert): array
    {
        $recipients = Settings::getList('email_to');
        $subject = sprintf('[%s] %s', Settings::getString('venue_name'), $alert->title);
        return Mailer::send($recipients, $subject, self::textBody($alert), self::htmlBody($alert));
    }

    /** @return array{ok:bool,message:string} */
    public static function sendTest(): array
    {
        $recipients = Settings::getList('email_to');
        if ($recipients === []) {
            return ['ok' => false, 'message' => 'Add at least one recipient address first.'];
        }
        $alert = new Alert(
            Alert::KIND_TEST,
            'Storm Watch test alert',
            sprintf(
                'This is a test from the Storm Watch dashboard for %s. If this arrives, lightning alerts will reach this inbox.',
                Settings::getString('venue_name')
            )
        );
        $alert->details['Sent'] = $alert->localTime();
        $subject = sprintf('[%s] Storm Watch test alert', Settings::getString('venue_name'));
        return Mailer::send($recipients, $subject, self::textBody($alert), self::htmlBody($alert));
    }

    private static function textBody(Alert $alert): string
    {
        $lines = [
            strtoupper($alert->title),
            str_repeat('=', min(60, mb_strlen($alert->title))),
            '',
            $alert->summary,
            '',
        ];
        foreach ($alert->detailRows() as $label => $value) {
            $lines[] = sprintf('%-20s %s', $label . ':', $value);
        }
        $lines[] = '';
        $lines[] = Settings::getString('venue_name') . ' — ' . Settings::getString('venue_address');
        $url = self::dashboardUrl();
        if ($url !== null) {
            $lines[] = $url;
        }
        $lines[] = '';
        $lines[] = 'Sent by Storm Watch. Manage alerts from the dashboard settings.';
        return implode("\n", $lines);
    }

    private static function htmlBody(Alert $alert): string
    {
        $colour = $alert->colour();
        $rows = '';
        foreach ($alert->detailRows() as $label => $value) {
            $rows .= sprintf(
                '<tr><td style="padding:6px 14px 6px 0;color:#5C6483;font-size:13px;white-space:nowrap;">%s</td>'
                . '<td style="padding:6px 0;color:#0F1526;font-size:14px;font-weight:600;">%s</td></tr>',
                Http::e($label),
                Http::e($value)
            );
        }

        $url = self::dashboardUrl();
        $button = $url === null ? '' : sprintf(
            '<p style="margin:22px 0 0;"><a href="%s" style="background:#131A2E;color:#ffffff;text-decoration:none;'
            . 'padding:11px 18px;border-radius:8px;font-size:14px;display:inline-block;">Open the dashboard</a></p>',
            Http::e($url)
        );

        return sprintf(
            '<!doctype html><html><body style="margin:0;padding:24px;background:#F3F5FA;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;'
            . 'background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #E2E6F0;">'
            . '<tr><td style="background:%s;height:6px;"></td></tr>'
            . '<tr><td style="padding:26px 28px 28px;">'
            . '<h1 style="margin:0 0 6px;font-size:19px;color:#0F1526;">%s %s</h1>'
            . '<p style="margin:0 0 18px;font-size:14.5px;line-height:1.55;color:#3D4560;">%s</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0">%s</table>'
            . '%s'
            . '<p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #E9ECF4;font-size:12px;color:#8A92AC;">'
            . '%s<br>%s</p>'
            . '</td></tr></table></body></html>',
            Http::e($colour),
            $alert->emoji(),
            Http::e($alert->title),
            Http::e($alert->summary),
            $rows,
            $button,
            Http::e(Settings::getString('venue_name')),
            Http::e(Settings::getString('venue_address'))
        );
    }

    private static function dashboardUrl(): ?string
    {
        $url = Http::absoluteUrl('index.php');
        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
