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

php -S "127.0.0.1:${PORT}" -t "${ROOT}/public" >"${WORK}/server.log" 2>&1 &
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
