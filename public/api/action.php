<?php
/**
 * Operator actions from the dashboard: mute, un-mute, drop a simulated strike,
 * clear the strike history, and the connection tests on the settings screen.
 *
 * Everything here requires a signed-in user and a valid CSRF token.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use StormWatch\AlertEngine;
use StormWatch\App;
use StormWatch\Events;
use StormWatch\Geo;
use StormWatch\Http;
use StormWatch\Notifiers\EmailNotifier;
use StormWatch\Notifiers\SlackNotifier;
use StormWatch\Providers\Blitzortung;
use StormWatch\Providers\Rest;
use StormWatch\Providers\Simulator;
use StormWatch\Runner;
use StormWatch\Settings;
use StormWatch\Strikes;

App::boot('auth', true);

if (!Http::isPost()) {
    Http::jsonError('This endpoint expects a POST request.', 405);
}
if (!Http::checkCsrf()) {
    Http::jsonError('Your session has expired. Reload the page and try again.', 419);
}

$body = Http::jsonBody();
$action = (string) ($body['action'] ?? $_POST['action'] ?? '');

switch ($action) {
    case 'simulate':
        $distance = isset($body['distance_mi']) ? (float) $body['distance_mi'] : null;
        $strike = Simulator::singleStrike($distance);
        if ($strike === null) {
            Http::jsonError('That strike could not be stored — it may be outside the display radius.', 422);
        }
        AlertEngine::evaluate();
        Http::json([
            'ok' => true,
            'message' => sprintf(
                'Simulated a strike %s %s of the venue.',
                Geo::formatDistance((float) $strike['distance_mi'], Settings::getString('units')),
                Geo::compassPoint((float) $strike['bearing_deg'])
            ),
            'state' => AlertEngine::publicState(),
        ]);
        // no break — Http::json exits

    case 'mute':
        $minutes = (int) ($body['minutes'] ?? 30);
        $until = AlertEngine::mute($minutes);
        Http::json([
            'ok' => true,
            'message' => sprintf('Alerts muted for %d minutes.', $minutes),
            'state' => AlertEngine::publicState(),
            'muted_until' => $until,
        ]);

    case 'unmute':
        AlertEngine::unmute();
        Http::json(['ok' => true, 'message' => 'Alerts are active again.', 'state' => AlertEngine::publicState()]);

    case 'clear_strikes':
        $removed = Strikes::deleteAll();
        AlertEngine::evaluate(false);
        Events::log('system.strikes_cleared', Events::SEVERITY_WARNING, sprintf('Strike history cleared (%d rows).', $removed), []);
        Http::json(['ok' => true, 'message' => sprintf('Cleared %d stored strike(s).', $removed), 'state' => AlertEngine::publicState()]);

    case 'test_slack':
        $result = SlackNotifier::sendTest();
        Events::log(
            'slack.test',
            $result['ok'] ? Events::SEVERITY_INFO : Events::SEVERITY_ERROR,
            ($result['ok'] ? 'Slack test succeeded: ' : 'Slack test failed: ') . $result['message'],
            []
        );
        Http::json(['ok' => $result['ok'], 'message' => $result['message']], $result['ok'] ? 200 : 200);

    case 'test_email':
        $result = EmailNotifier::sendTest();
        Events::log(
            'email.test',
            $result['ok'] ? Events::SEVERITY_INFO : Events::SEVERITY_ERROR,
            ($result['ok'] ? 'Email test succeeded: ' : 'Email test failed: ') . $result['message'],
            []
        );
        Http::json(['ok' => $result['ok'], 'message' => $result['message']]);

    case 'test_source':
        $provider = Settings::getString('provider');
        if ($provider === 'blitzortung') {
            $result = Blitzortung::test(12);
        } elseif ($provider === 'rest') {
            $result = Rest::test();
        } elseif ($provider === 'relay') {
            $result = [
                'ok' => true,
                'message' => 'The browser relay is fed by this page. Open the dashboard and check the source status line there.',
                'detail' => [],
            ];
        } else {
            $tick = Simulator::tick();
            $result = ['ok' => true, 'message' => $tick['message'], 'detail' => []];
        }
        Events::log(
            'source.test',
            $result['ok'] ? Events::SEVERITY_INFO : Events::SEVERITY_ERROR,
            sprintf('%s test: %s', $provider, $result['message']),
            []
        );
        Http::json(['ok' => $result['ok'], 'message' => $result['message'], 'detail' => $result['detail'] ?? []]);

    case 'run_tick':
        $result = Runner::tick();
        Http::json(['ok' => $result['ok'], 'message' => $result['message'], 'state' => AlertEngine::publicState()]);

    default:
        Http::jsonError('Unknown action.', 400);
}
