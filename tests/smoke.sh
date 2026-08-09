#!/usr/bin/env bash
#
# End-to-end smoke test: installs Storm Watch into a scratch directory, drives
# the real HTTP endpoints with curl, and checks the answers.
#
#   ./tests/smoke.sh
#
# Requires php and curl. Starts a throwaway PHP development server; it never
# touches an existing installation.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${SMOKE_PORT:-8399}"
BASE="http://127.0.0.1:${PORT}"
WORK="$(mktemp -d)"
JAR="${WORK}/cookies.txt"
ANON_JAR="${WORK}/anon.txt"
PASS=0
FAIL=0

green() { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
red()   { printf '  \033[31m✗ %s\033[0m\n' "$1"; FAIL=$((FAIL+1)); }
group() { printf '\n\033[1m%s\033[0m\n' "$1"; }

check() { # check <description> <condition-result>
  if [ "$2" = "0" ]; then green "$1"; else red "$1"; fi
}

contains() { # contains <haystack-file> <needle> <description>
  if grep -qF -- "$2" "$1"; then green "$3"; else red "$3 (missing: $2)"; fi
}

status_is() { # status_is <expected> <actual> <description>
  if [ "$1" = "$2" ]; then green "$3"; else red "$3 (expected HTTP $1, got $2)"; fi
}

cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  # Put back any installation that was here before the test ran.
  rm -f "${ROOT}/public/smoke-slow-probe.php"
  rm -rf "${ROOT}/data" "${ROOT}/config/config.php"
  if [ -d "${WORK}/backup-data" ]; then mv "${WORK}/backup-data" "${ROOT}/data"; fi
  if [ -f "${WORK}/backup-config.php" ]; then mv "${WORK}/backup-config.php" "${ROOT}/config/config.php"; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

# Stash any existing install so a developer's local setup survives the run.
[ -d "${ROOT}/data" ] && cp -r "${ROOT}/data" "${WORK}/backup-data"
[ -f "${ROOT}/config/config.php" ] && cp "${ROOT}/config/config.php" "${WORK}/backup-config.php"
rm -rf "${ROOT}/data" "${ROOT}/config/config.php"
mkdir -p "${ROOT}/data"

# Workers, so the suite can tell "one request blocks another" from "the test
# server only runs one request at a time". A single-process server would
# serialise everything no matter what the application does.
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:${PORT}" -t "${ROOT}/public" >"${WORK}/server.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
  if curl -s -o /dev/null "${BASE}/setup.php"; then break; fi
  sleep 0.25
done

group "Before installation"
code=$(curl -s -o "${WORK}/root.html" -w '%{http_code}' -L -c "$JAR" -b "$JAR" "${BASE}/index.php")
status_is 200 "$code" "the dashboard is reachable"
contains "${WORK}/root.html" "Set up Storm Watch" "an uninstalled app redirects to the setup wizard"
contains "${WORK}/root.html" "Server check" "the wizard shows the server requirement check"

code=$(curl -s -o "${WORK}/api.json" -w '%{http_code}' "${BASE}/api/state.php")
status_is 503 "$code" "the API reports that setup has not run"

group "Installation"
CSRF=$(grep -o 'name="csrf_token" value="[^"]*"' "${WORK}/root.html" | head -1 | sed 's/.*value="//;s/"//')
check "the wizard issues a CSRF token" "$([ -n "$CSRF" ] && echo 0 || echo 1)"

code=$(curl -s -o "${WORK}/install.html" -w '%{http_code}' -c "$JAR" -b "$JAR" \
  -d "csrf_token=${CSRF}" \
  -d "db_driver=sqlite" \
  -d "username=smoketest" \
  -d "password=a-good-test-passphrase" \
  -d "password_confirm=a-good-test-passphrase" \
  -d "venue_name=Castle Fun Center" \
  -d "venue_address=109 Brookside Ave, Chester, NY 10918" \
  -d "venue_lat=41.3608" \
  -d "venue_lon=-74.2854" \
  -d "timezone=America/New_York" \
  "${BASE}/setup.php")
status_is 302 "$code" "the installer accepts the form and redirects"
check "config/config.php was written" "$([ -f "${ROOT}/config/config.php" ] && echo 0 || echo 1)"
check "the SQLite database was created" "$([ -f "${ROOT}/data/stormwatch.sqlite" ] && echo 0 || echo 1)"

code=$(curl -s -o "${WORK}/setup2.html" -w '%{http_code}' -L "${BASE}/setup.php")
contains "${WORK}/setup2.html" "Sign in" "setup refuses to run again once an account exists"

group "Authentication"
code=$(curl -s -o "${WORK}/anon.html" -w '%{http_code}' -L -c "$ANON_JAR" -b "$ANON_JAR" "${BASE}/index.php")
contains "${WORK}/anon.html" "Sign in" "a signed-out visitor gets the login page"

code=$(curl -s -o /dev/null -w '%{http_code}' -c "$ANON_JAR" -b "$ANON_JAR" "${BASE}/api/state.php")
status_is 401 "$code" "the API refuses an unauthenticated caller"

code=$(curl -s -o "${WORK}/settings-anon.html" -w '%{http_code}' -L -c "$ANON_JAR" -b "$ANON_JAR" "${BASE}/settings.php")
contains "${WORK}/settings-anon.html" "Sign in" "settings require signing in"

# The installer signs the operator in, so this jar is already authenticated.
group "Dashboard"
code=$(curl -s -o "${WORK}/dash.html" -w '%{http_code}' -c "$JAR" -b "$JAR" "${BASE}/index.php")
status_is 200 "$code" "the dashboard renders"
contains "${WORK}/dash.html" "Castle Fun Center" "the venue name appears"
contains "${WORK}/dash.html" "Live map" "the map panel is present"
contains "${WORK}/dash.html" "leaflet" "Leaflet is loaded"
contains "${WORK}/dash.html" "sw-boot" "the boot data block is present"
contains "${WORK}/dash.html" 'class="alert-banner unknown"' "the banner starts neutral, not on a green all-clear"

# These two are static checks on the dashboard script, because the PHP suite
# cannot execute it. A thrown exception during map setup used to kill the whole
# script before it ever polled — the map looked perfect and nothing else on the
# page ever updated — so the safety net around it is worth pinning down.
contains "${ROOT}/public/assets/js/dashboard.js" "catch (mapError)" \
  "a failure while drawing the map cannot stop the dashboard polling"
if grep -q 'circle(.*)\.getBounds()' "${ROOT}/public/assets/js/dashboard.js"; then
  red "the map is fitted without projecting a detached circle through the map"
else
  green "the map is fitted without projecting a detached circle through the map"
fi

DASHV=$(grep -o 'js/dashboard\.js?v=[^"]*' "${WORK}/dash.html" | head -1 | sed 's/.*v=//')
check "dashboard.js is versioned by the file itself, not a fixed number (v=${DASHV})" \
  "$([ -n "$DASHV" ] && [ "$DASHV" != "1.0.0" ] && echo 0 || echo 1)"
touch "${ROOT}/public/assets/js/dashboard.js"
curl -s -o "${WORK}/dash2.html" -c "$JAR" -b "$JAR" "${BASE}/index.php"
DASHV2=$(grep -o 'js/dashboard\.js?v=[^"]*' "${WORK}/dash2.html" | head -1 | sed 's/.*v=//')
check "changing an asset changes its URL, so browsers refetch it" \
  "$([ -n "$DASHV2" ] && [ "$DASHV" != "$DASHV2" ] && echo 0 || echo 1)"
contains "${WORK}/dash.html" "Strike log" "the strike log panel is present"
contains "${WORK}/dash.html" "Data source" "the data source panel is present"

code=$(curl -s -o "${WORK}/state.json" -w '%{http_code}' -c "$JAR" -b "$JAR" "${BASE}/api/state.php")
status_is 200 "$code" "the state API answers"
contains "${WORK}/state.json" '"ok":true' "the state API reports success"
contains "${WORK}/state.json" '"level":"clear"' "the initial alert level is clear"

group "Alerting"
CSRF=$(grep -o '"csrf":"[^"]*"' "${WORK}/dash.html" | head -1 | sed 's/"csrf":"//;s/"//')
check "the dashboard exposes a CSRF token to its scripts" "$([ -n "$CSRF" ] && echo 0 || echo 1)"

code=$(curl -s -o "${WORK}/nocsrf.json" -w '%{http_code}' -c "$JAR" -b "$JAR" \
  -H 'Content-Type: application/json' -d '{"action":"simulate"}' "${BASE}/api/action.php")
status_is 419 "$code" "an action without a CSRF token is rejected"

code=$(curl -s -o "${WORK}/sim.json" -w '%{http_code}' -c "$JAR" -b "$JAR" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: ${CSRF}" \
  -d '{"action":"simulate","distance_mi":3}' "${BASE}/api/action.php")
status_is 200 "$code" "a simulated strike is accepted"
contains "${WORK}/sim.json" '"ok":true' "the simulated strike was stored"
contains "${WORK}/sim.json" '"level":"warning"' "a strike inside the alert radius raises a warning"

curl -s -o "${WORK}/state2.json" -c "$JAR" -b "$JAR" "${BASE}/api/state.php"
contains "${WORK}/state2.json" '"level":"warning"' "the dashboard state reflects the warning"
contains "${WORK}/state2.json" '"close":1' "the statistics count the close strike"

code=$(curl -s -o "${WORK}/mute.json" -w '%{http_code}' -c "$JAR" -b "$JAR" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: ${CSRF}" \
  -d '{"action":"mute","minutes":15}' "${BASE}/api/action.php")
contains "${WORK}/mute.json" '"muted_until"' "alerts can be muted"

curl -s -o /dev/null -c "$JAR" -b "$JAR" -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: ${CSRF}" -d '{"action":"unmute"}' "${BASE}/api/action.php"

group "Settings"
code=$(curl -s -o "${WORK}/settings.html" -w '%{http_code}' -c "$JAR" -b "$JAR" "${BASE}/settings.php?tab=slack")
status_is 200 "$code" "the Slack settings tab renders"
contains "${WORK}/settings.html" "Bot user OAuth token" "the bot token field is present"
contains "${WORK}/settings.html" "Channel" "the channel field is present"

SCSRF=$(grep -o 'name="csrf_token" value="[^"]*"' "${WORK}/settings.html" | head -1 | sed 's/.*value="//;s/"//')
code=$(curl -s -o "${WORK}/saved.html" -w '%{http_code}' -c "$JAR" -b "$JAR" \
  -d "csrf_token=${SCSRF}" -d "action=save" -d "slack_mode=bot" \
  -d "slack_bot_token=NOT-A-REAL-TOKEN-smoke-test" -d "slack_channel=#storm-alerts" \
  -d "slack_mention=here" "${BASE}/settings.php?tab=slack")
contains "${WORK}/saved.html" "Settings saved" "Slack settings save"
if grep -qF "NOT-A-REAL-TOKEN-smoke-test" "${WORK}/saved.html"; then
  red "the saved token is not echoed back to the browser"
else
  green "the saved token is not echoed back to the browser"
fi
if grep -aqF "NOT-A-REAL-TOKEN-smoke-test" "${ROOT}/data/stormwatch.sqlite"; then
  red "the token is encrypted at rest"
else
  green "the token is encrypted at rest"
fi

# Re-read every screen now that a token is stored, and prove none of them
# hand it back to the browser.
LEAKED=0
for page in "index.php" "settings.php?tab=slack" "history.php" "api/state.php"; do
  curl -s -o "${WORK}/leak.html" -c "$JAR" -b "$JAR" "${BASE}/${page}"
  if grep -qF "NOT-A-REAL-TOKEN-smoke-test" "${WORK}/leak.html"; then LEAKED=1; fi
done
check "the stored token appears on no page or API response" "$LEAKED"

# Radii must stay nested.
code=$(curl -s -o "${WORK}/badradius.html" -w '%{http_code}' -c "$JAR" -b "$JAR" \
  -d "csrf_token=${SCSRF}" -d "action=save" -d "alert_radius_mi=25" -d "watch_radius_mi=5" \
  -d "display_radius_mi=30" "${BASE}/settings.php?tab=alerts")
contains "${WORK}/badradius.html" "Nothing was saved" "an inconsistent radius set is rejected"

# The map style reaches the dashboard, where the script reads it to pick both
# the tiles and the ring colours.
VCSRF=$(curl -s -c "$JAR" -b "$JAR" "${BASE}/settings.php?tab=venue" \
  | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -o "${WORK}/mapstyle.html" -c "$JAR" -b "$JAR" \
  -d "csrf_token=${VCSRF}" -d "action=save" -d "map_style=light" \
  "${BASE}/settings.php?tab=venue"
contains "${WORK}/mapstyle.html" "Settings saved" "the map style saves"
curl -s -o "${WORK}/dash-style.html" -c "$JAR" -b "$JAR" "${BASE}/index.php"
contains "${WORK}/dash-style.html" '"mapStyle":"light"' "the map style reaches the dashboard script"
curl -s -o /dev/null -c "$JAR" -b "$JAR" \
  -d "csrf_token=${VCSRF}" -d "action=save" -d "map_style=muted" "${BASE}/settings.php?tab=venue"

# The data source tab, and the provider limits that keep a capped endpoint
# honest. A watch ring wider than the feed answers for is the dangerous case:
# it looks fine and silently stops seeing storms.
code=$(curl -s -o "${WORK}/source.html" -w '%{http_code}' -c "$JAR" -b "$JAR" "${BASE}/settings.php?tab=source")
status_is 200 "$code" "the data source tab renders"
contains "${WORK}/source.html" "Largest radius the endpoint allows" "the radius cap field is present"
contains "${WORK}/source.html" "Endpoint serves the last" "the data window field is present"
contains "${WORK}/source.html" "lightning flash" "the free-tier Xweather preset is offered"
contains "${WORK}/source.html" "Verify the mapping" "the mapping check is offered"

# The mapping check is REST-only; the provider is still the simulator here.
curl -s -o "${WORK}/probe.json" -c "$JAR" -b "$JAR" -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: ${CSRF}" -d '{"action":"probe_mapping"}' "${BASE}/api/action.php"
contains "${WORK}/probe.json" "only applies to the REST provider" "the mapping check refuses a non-REST provider"

code=$(curl -s -o "${WORK}/capped.html" -w '%{http_code}' -c "$JAR" -b "$JAR" \
  -d "csrf_token=${SCSRF}" -d "action=save" -d "provider=rest" \
  -d "rest_endpoint=https://data.api.xweather.com/lightning/flash/closest?p={lat},{lon}" \
  -d "rest_max_radius_mi=25" -d "rest_data_window_minutes=5" \
  -d "rest_poll_seconds=120" -d "rest_active_poll_seconds=60" "${BASE}/settings.php?tab=source")
contains "${WORK}/capped.html" "Settings saved" "a capped endpoint inside its limits saves"

code=$(curl -s -o "${WORK}/slowpoll.html" -w '%{http_code}' -c "$JAR" -b "$JAR" \
  -d "csrf_token=${SCSRF}" -d "action=save" -d "rest_poll_seconds=300" "${BASE}/settings.php?tab=source")
contains "${WORK}/slowpoll.html" "Nothing was saved" "polling slower than the endpoint's data window is rejected"

code=$(curl -s -o "${WORK}/widewatch.html" -w '%{http_code}' -c "$JAR" -b "$JAR" \
  -d "csrf_token=${SCSRF}" -d "action=save" -d "alert_radius_mi=10" -d "watch_radius_mi=30" \
  -d "display_radius_mi=30" "${BASE}/settings.php?tab=alerts")
contains "${WORK}/widewatch.html" "Nothing was saved" "a watch radius beyond the endpoint cap is rejected"

# Put the source back so the rest of the run is unaffected.
curl -s -o /dev/null -c "$JAR" -b "$JAR" \
  -d "csrf_token=${SCSRF}" -d "action=save" -d "provider=simulator" \
  -d "rest_max_radius_mi=0" -d "rest_data_window_minutes=0" "${BASE}/settings.php?tab=source"

group "Concurrency"
# A slow authenticated request must not freeze the dashboard. PHP holds the
# session lock for the whole of a request, so before this was fixed every poll
# queued behind whichever connection test or mapping check the operator had
# just started: the page sat on its "Loading…" placeholders for the duration
# and never said why. A deterministic sleep stands in for that provider call so
# the check does not depend on the network being slow.
#
# The probe follows api/action.php's real sequence — boot, check the CSRF
# token, then do the slow thing — because the token check is itself a session
# read, and a read that re-opens the session takes the lock straight back.
cat > "${ROOT}/public/smoke-slow-probe.php" <<'PHP'
<?php
require __DIR__ . '/../src/bootstrap.php';
\StormWatch\App::boot('auth', true);
if (!\StormWatch\Http::checkCsrf()) {
    \StormWatch\Http::jsonError('Bad CSRF token.', 419);
}
sleep(6);
\StormWatch\Http::json(['ok' => true]);
PHP

curl -s -o "${WORK}/probe-slow.json" -b "$JAR" -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: ${CSRF}" -d '{}' "${BASE}/smoke-slow-probe.php" &
PROBE_PID=$!
sleep 1
ELAPSED=$(curl -s -o /dev/null -w '%{time_total}' -b "$JAR" "${BASE}/api/state.php")
check "the dashboard keeps polling while a slow action runs (${ELAPSED}s)" \
  "$(awk -v t="$ELAPSED" 'BEGIN { print (t < 3) ? 0 : 1 }')"
wait "$PROBE_PID" 2>/dev/null
contains "${WORK}/probe-slow.json" '"ok":true' "the slow action itself still authenticates and completes"
rm -f "${ROOT}/public/smoke-slow-probe.php"

group "Scheduled runs and ingest"
CRON_TOKEN=$(php -r '
require "'"${ROOT}"'/src/bootstrap.php";
echo StormWatch\Settings::getString("cron_token");
')
INGEST_TOKEN=$(php -r '
require "'"${ROOT}"'/src/bootstrap.php";
echo StormWatch\Settings::getString("ingest_token");
')

code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/api/cron.php?token=wrong&job=tick")
status_is 401 "$code" "the web cron rejects a bad token"

code=$(curl -s -o "${WORK}/cron.json" -w '%{http_code}' "${BASE}/api/cron.php?token=${CRON_TOKEN}&job=tick")
status_is 200 "$code" "the web cron accepts the right token"
contains "${WORK}/cron.json" '"job":"tick"' "the tick job ran"

code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' \
  -H 'X-Ingest-Token: wrong' -d '{"strikes":[]}' "${BASE}/api/ingest.php")
status_is 401 "$code" "ingest rejects a bad token"

code=$(curl -s -o "${WORK}/ingest.json" -w '%{http_code}' -H 'Content-Type: application/json' \
  -H "X-Ingest-Token: ${INGEST_TOKEN}" \
  -d '{"strikes":[{"lat":41.40,"lon":-74.30,"time":'"$(date +%s)"'000000000},{"lat":10.0,"lon":10.0,"time":0}]}' \
  "${BASE}/api/ingest.php")
status_is 200 "$code" "ingest accepts the right token"
contains "${WORK}/ingest.json" '"stored":1' "only the strike inside the display radius is stored"

group "History and CLI"
code=$(curl -s -o "${WORK}/history.html" -w '%{http_code}' -c "$JAR" -b "$JAR" "${BASE}/history.php")
status_is 200 "$code" "the history page renders"
contains "${WORK}/history.html" "Activity log" "the activity log is present"
contains "${WORK}/history.html" "alert.warning" "the warning we triggered is logged"

php "${ROOT}/bin/stormwatch.php" status >"${WORK}/cli.txt" 2>&1
contains "${WORK}/cli.txt" "Castle Fun Center" "the CLI reports the venue"
contains "${WORK}/cli.txt" "Alert level" "the CLI reports the alert level"

php "${ROOT}/bin/tick.php" -v >"${WORK}/tick.txt" 2>&1
contains "${WORK}/tick.txt" "tick ok" "bin/tick.php runs cleanly"

php "${ROOT}/bin/worker.php" -v >"${WORK}/worker.txt" 2>&1
contains "${WORK}/worker.txt" "worker" "bin/worker.php runs cleanly"

group "Subfolder mount"
# Storm Watch is normally installed in a subfolder of an existing site. The
# built-in server ignores .htaccess, so tests/router.php reproduces what Apache
# does there: /mount/x is served from public/x with SCRIPT_NAME carrying the
# extra /public segment. Everything the browser sees has to stay on /mount.
MOUNT="/stormwatch"
SUBPORT=$((PORT + 1))
SUBBASE="http://127.0.0.1:${SUBPORT}${MOUNT}"
SUBJAR="${WORK}/sub.txt"

SW_MOUNT="$MOUNT" php -S "127.0.0.1:${SUBPORT}" -t "${ROOT}" "${ROOT}/tests/router.php" \
  >"${WORK}/sub-server.log" 2>&1 &
SUB_PID=$!
for _ in $(seq 1 40); do
  if curl -s -o /dev/null "${SUBBASE}/login.php"; then break; fi
  sleep 0.25
done

curl -s -c "$SUBJAR" -b "$SUBJAR" -o "${WORK}/sub-login.html" "${SUBBASE}/login.php"
# Netscape cookie jar: domain, tailmatch, path, secure, expires, name, value.
# httponly cookies are written with a "#HttpOnly_" prefix on the domain.
COOKIE_PATH=$(awk -F'\t' '/stormwatch/ && NF >= 6 { print $3; exit }' "$SUBJAR")
if [ "$COOKIE_PATH" = "$MOUNT" ]; then
  green "the session cookie is scoped to the mount, not to /public"
else
  red "the session cookie is scoped to the mount (got '${COOKIE_PATH:-none}', wanted '${MOUNT}')"
fi

contains "${WORK}/sub-login.html" "${MOUNT}/assets/css/app.css" "asset URLs carry the mount path"
if grep -qF "${MOUNT}/public/" "${WORK}/sub-login.html"; then
  red "no URL leaks the internal public/ folder"
else
  green "no URL leaks the internal public/ folder"
fi

# The real test: sign in at the clean subfolder URL. Before the mount-point
# fix this failed with "session expired" because the cookie path did not match.
SUBCSRF=$(grep -o 'name="csrf_token" value="[^"]*"' "${WORK}/sub-login.html" | head -1 | sed 's/.*value="//;s/"//')
code=$(curl -s -o "${WORK}/sub-auth.html" -w '%{http_code}' -c "$SUBJAR" -b "$SUBJAR" \
  -d "csrf_token=${SUBCSRF}" -d "username=smoketest" -d "password=a-good-test-passphrase" \
  "${SUBBASE}/login.php")
status_is 302 "$code" "signing in works at the subfolder URL"
if grep -qF "session expired" "${WORK}/sub-auth.html"; then
  red "the session survives the login POST"
else
  green "the session survives the login POST"
fi

code=$(curl -s -o "${WORK}/sub-dash.html" -w '%{http_code}' -c "$SUBJAR" -b "$SUBJAR" "${SUBBASE}/index.php")
status_is 200 "$code" "the dashboard loads at the subfolder URL"
contains "${WORK}/sub-dash.html" "\"stateUrl\":\"${MOUNT}/api/state.php\"" "the dashboard polls the mounted API path"
contains "${WORK}/sub-dash.html" "\"loginUrl\":\"${MOUNT}/login.php\"" "the sign-in redirect target is mounted"

code=$(curl -s -o /dev/null -w '%{http_code}' -c "$SUBJAR" -b "$SUBJAR" "${SUBBASE}/api/state.php")
status_is 200 "$code" "the API answers at the subfolder URL"

code=$(curl -s -o "${WORK}/sub-settings.html" -w '%{http_code}' -c "$SUBJAR" -b "$SUBJAR" "${SUBBASE}/settings.php?tab=system")
status_is 200 "$code" "settings load at the subfolder URL"

# The kiosk and web-cron links are absolute, so they follow the public base URL
# rather than the current request. Point it at the mount, as a real subfolder
# install would have recorded at setup, and check the links follow.
php "${ROOT}/bin/stormwatch.php" set public_base_url "http://127.0.0.1:${SUBPORT}${MOUNT}" >/dev/null 2>&1
curl -s -o "${WORK}/sub-settings2.html" -c "$SUBJAR" -b "$SUBJAR" "${SUBBASE}/settings.php?tab=system"
contains "${WORK}/sub-settings2.html" "${MOUNT}/api/cron.php" "the web cron URL includes the mount path"
contains "${WORK}/sub-settings2.html" "${MOUNT}/index.php?kiosk=" "the kiosk URL includes the mount path"
php "${ROOT}/bin/stormwatch.php" set public_base_url "" >/dev/null 2>&1

code=$(curl -s -o /dev/null -w '%{http_code}' "${SUBBASE}/public/settings.php")
status_is 301 "$code" "a direct hit on /public is redirected to the clean URL"

# Deep link while signed out: the stored "next" must not end up with the mount
# applied twice (/stormwatch/stormwatch/settings.php).
DEEPJAR="${WORK}/deep.txt"
NEXT=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$DEEPJAR" -b "$DEEPJAR" "${SUBBASE}/history.php")
case "$NEXT" in
  *"${MOUNT}${MOUNT}"*) red "the sign-in redirect does not double the mount path (got ${NEXT})" ;;
  *"login.php?next="*)  green "the sign-in redirect does not double the mount path" ;;
  *)                    red "signing out of a deep link should send you to login (got ${NEXT:-nothing})" ;;
esac

curl -s -o "${WORK}/deep-login.html" -c "$DEEPJAR" -b "$DEEPJAR" -L "${SUBBASE}/history.php"
DEEPCSRF=$(grep -o 'name="csrf_token" value="[^"]*"' "${WORK}/deep-login.html" | head -1 | sed 's/.*value="//;s/"//')
DEEPNEXT=$(grep -o 'name="next" value="[^"]*"' "${WORK}/deep-login.html" | head -1 | sed 's/.*value="//;s/"//')
LANDED=$(curl -s -o /dev/null -w '%{redirect_url}' -c "$DEEPJAR" -b "$DEEPJAR" \
  -d "csrf_token=${DEEPCSRF}" -d "next=${DEEPNEXT}" \
  -d "username=smoketest" -d "password=a-good-test-passphrase" "${SUBBASE}/login.php")
if [ "$LANDED" = "http://127.0.0.1:${SUBPORT}${MOUNT}/history.php" ]; then
  green "signing in from a deep link lands on the page that was asked for"
else
  red "signing in from a deep link lands on the right page (got ${LANDED:-nothing})"
fi

for path in "src/Http.php" "config/config.php" "data/stormwatch.sqlite" "bin/tick.php"; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "${SUBBASE}/${path}")
  status_is 403 "$code" "${path} is not reachable through the mount"
done

code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${SUBPORT}/index.php")
status_is 404 "$code" "nothing is served outside the mount"

kill "$SUB_PID" 2>/dev/null; wait "$SUB_PID" 2>/dev/null

group "Sign out"
curl -s -o /dev/null -c "$JAR" -b "$JAR" "${BASE}/logout.php"
code=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" "${BASE}/api/state.php")
status_is 401 "$code" "the session is gone after signing out"

group "Server log"
if grep -qiE 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' "${WORK}/server.log"; then
  red "no PHP errors were logged"
  grep -iE 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' "${WORK}/server.log" | head -10 | sed 's/^/      /'
else
  green "no PHP errors were logged"
fi

printf '\n'
if [ "$FAIL" -eq 0 ]; then
  printf '\033[32m%d checks passed.\033[0m\n' "$PASS"
  exit 0
fi
printf '\033[31m%d of %d checks failed.\033[0m\n' "$FAIL" "$((PASS+FAIL))"
exit 1
