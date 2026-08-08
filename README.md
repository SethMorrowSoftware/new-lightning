# Storm Watch

Lightning detection and alerting for a single venue. A live map of nearby
strikes, a configurable alert radius, and Slack and email alerts sent by the
server — so the venue is told about lightning whether or not anyone has a
browser open.

Built to run on ordinary shared cPanel hosting: PHP 8, Apache, and either a
SQLite file or a MySQL database. No Composer, no Node build step, nothing to
compile.

---

## What this is, and what it replaces

This started as `castle-storm-watch_5.html` — a single-file demo with a
simulated storm cell. That demo looked right but could not actually alert
anyone: everything lived in the browser tab, so closing the tab ended the
monitoring, and the Slack and email fields were placeholders.

Storm Watch keeps the demo's design and moves the work to the server:

| | Demo | Storm Watch |
|---|---|---|
| Lightning data | Simulated in the tab | Blitzortung, a commercial REST feed, a browser relay, or the simulator |
| Alerting | A toast in the open tab | Slack and email from cron, plus browser notifications |
| Alert state | Lost on refresh | Stored, with a proper all-clear timer |
| Settings | `localStorage` on one device | Database-backed, shared by everyone, secrets encrypted |
| Access | Anyone with the URL | Accounts, sessions, CSRF, login throttling |
| Health | None | Feed and cron monitoring that alerts you when data stops |

The original demo file is kept in the repository for reference.

---

## Requirements

- PHP 8.0 or newer with `pdo_sqlite` **or** `pdo_mysql`
- `openssl` and either `curl` or `allow_url_fopen`
- The ability to run a cron job once a minute (or an external cron service —
  see [Web cron](#no-cron-jobs-web-cron-instead))

Every mainstream cPanel host meets this. The setup wizard checks each item and
tells you exactly what is missing.

---

## Installing on cPanel

### 1. Upload

**Subfolder of an existing site — the usual case.** Upload the whole repository
into a folder under `public_html`:

```
/home/USER/public_html/stormwatch/
```

and visit `https://yourdomain.com/stormwatch/`. Nothing else to configure: the
root `.htaccess` serves every request out of `public/`, blocks `config/`,
`data/`, `src/`, `bin/` and `tests/`, and keeps `public` itself out of the
address bar. It needs `mod_rewrite`, which cPanel enables by default.

The folder name is yours to choose — `stormwatch`, `weather`, `lightning` —
and it can be nested (`/tools/stormwatch/`). The app works out where it is
mounted on its own.

**Its own domain or subdomain.** If you would rather give Storm Watch its own
address, put the application outside the web root and point the document root
at `public/`:

```
/home/USER/stormwatch/          <- upload the whole repository here
/home/USER/stormwatch/public/   <- point the document root here
```

In cPanel: **Domains** → **Create A New Domain** (or edit an existing one) and
set the document root to `/home/USER/stormwatch/public`. This keeps the
application files off the web entirely, so it is the tidier arrangement where
it is available.

### 2. Permissions

`config/` and `data/` must be writable by PHP. In cPanel **File Manager**,
select each folder → **Permissions** → `0755` (some hosts need `0775`).

### 3. Run the wizard

Open the site in a browser. You will land on the setup wizard, which checks the
server, then asks for:

- **Database** — SQLite (nothing to set up) or MySQL. For MySQL, create the
  database and user first in cPanel → **MySQL® Databases**, and give the user
  **All Privileges**.
- **Administrator** — the account you will sign in with.
- **Venue** — name, address, latitude, longitude and time zone. Get the
  coordinates by looking up the address on any mapping site; every distance in
  the system is measured from that point.

The wizard writes `config/config.php`, creates the tables, and signs you in.

### 4. Install the cron jobs

This is the step that makes alerting work. Without it the dashboard still
draws, but nothing is ever sent. The dashboard says so in red when cron is not
running.

cPanel → **Cron Jobs** → **Add New Cron Job**, "Once Per Minute (`* * * * *`)":

```
* * * * * /usr/local/bin/php /home/USER/stormwatch/bin/tick.php >/dev/null 2>&1
* * * * * /usr/local/bin/php /home/USER/stormwatch/bin/worker.php >/dev/null 2>&1
```

Replace `/home/USER/stormwatch` with your real path. If `/usr/local/bin/php` is
not the right binary, cPanel's **Terminal** or the top of the Cron Jobs page
usually shows the correct one; on EasyApache hosts it is often
`/opt/cpanel/ea-php82/root/usr/bin/php`.

- `tick.php` polls REST feeds, re-evaluates the alert state (so the all-clear
  fires on a quiet feed), and prunes old data. **Always needed.**
- `worker.php` streams the Blitzortung feed for 50 seconds and exits. It does
  nothing on other providers, so it is safe to leave installed either way.

Both take a lock, so a slow run is skipped rather than piling up.

---

## Choosing a data source

Settings → **Data source**. Press **Test this source** after any change; it
reports precisely what happened.

**Blitzortung live feed** — free, worldwide, run by a volunteer network of
receivers. The server connects directly. This is the best default. Its one
catch is that the feed uses port 3000, and some shared hosts only allow
outbound 80 and 443. The test will tell you within seconds if that is your
situation.

**Browser relay** — the same free Blitzortung data, fetched by a browser tab
instead of the server, then posted back. Use this when the direct feed is
blocked. It needs a tab left open somewhere that stays on; the venue's wall
display is ideal. Alerts are still sent by the server, so Slack keeps working
normally — only the data collection depends on the tab.

**REST endpoint** — for a commercial feed with a support contract behind it.
Configure the URL, how the API key is sent, and a dot-path mapping from the
response to latitude, longitude and time. It works with any JSON API that
returns a list of recent strikes. The endpoint URL accepts `{lat}`, `{lon}`,
`{radius_mi}`, `{radius_km}`, `{now}`, `{now_iso}`, `{since}` and `{since_iso}`.

**Simulator** — a drifting storm cell. Use it to prove the whole chain works,
including Slack delivery, before real weather arrives. The settings screen
warns while it is selected; never leave it on in production.

### Xweather

Settings → **Data source** → **REST endpoint**, then pick **Xweather —
lightning flash** from the Quick setup list. That fills in the endpoint, the
field mapping, the poll intervals, the provider limits and the allowance; you
add the credentials and press Save.

Xweather issues a **pair** of credentials, not a single key. The client ID goes
in the key field and the client secret in the secret field, sent as
`client_id` and `client_secret`.

**Which endpoint.** Xweather has two that look interchangeable and are not:

| Endpoint | Included with | Limits |
|---|---|---|
| `lightning/flash` | **every plan, free tier included** | 40km (~25 mi) radius, last 5 minutes only |
| `lightning` | the **Lightning Enterprise** add-on | none of the above |

Picking the wrong one is not a subtle failure, but it is a confusing one — the
credentials are accepted and the request still fails:

```
HTTP 401  {"success":false,"error":{"code":"insufficient_scope",
           "description":"The request requires a different account subscription level."}}
```

That is the account's plan, not the key. The app now says so in those words
rather than printing the raw body. Use the flash preset:

```
https://data.api.xweather.com/lightning/flash/closest
  ?p={lat},{lon}&radius={radius_mi}miles&limit=100&format=json
```

The response maps as `response` → `loc.lat` / `loc.long` / `ob.timestamp`.
Press **Test this source** after saving — it prints the first record it got
back, so a mismatch is immediately obvious rather than a silent zero.

**Living with the flash endpoint's two limits.** Both are filled in by the
preset, and both are enforced rather than left as a footnote:

- **25 mile radius.** Requests are clamped to it, so a wider *display* radius
  costs map coverage instead of failing the poll. A wider **watch** radius is
  refused outright — strikes in the gap would never be fetched, and the
  dashboard would stay green through a storm it could not see.
- **Last 5 minutes.** The idle poll is held to half the window (150s), so the
  default is **120s, not 300s**. Polling at the full five minutes leaves no
  margin: one late cron run and strikes arrive and expire unseen.

**Staying inside the free tier.** The free plan is 15,000 accesses a month.
A once-a-minute poll around the clock is about 43,000, so it has to be shaped:

| Lever | Effect |
|---|---|
| Operating hours (below) | Roughly halves it — a venue open 12 hours a day is monitored half the time |
| Slow poll when quiet | 2 minutes instead of 1 halves the rest |
| Fast poll during a storm | 1 minute, but only while a watch or alert is running |

With the venue's hours applied and the 120s / 60s pair the preset sets, a month
comes to roughly **10,500 accesses — about 70% of the allowance**. The Data
source tab shows the projection as you change the numbers, and refuses to leave
you guessing:

> Projected use: about 10,590 accesses a month — polling every 120s while quiet
> and every 60s during a storm, across 343 monitored hours (your operating
> hours), assuming 10 hours of storm activity. That fits inside your 15,000
> allowance.

The 5 minute idle poll costs about 4,600 a month instead, but only an endpoint
without the flash window can safely use it.

Usage is counted from the `X-Cost-Tokens` header Xweather returns, not from a
request count, because a request does not always cost one access — the price is
the product of endpoint, area and time-range multipliers.

If the allowance does run out, polling pauses and you get a **feed failure
alert**. It never stops quietly.

## Operating hours

Settings → **Alert rules** → **Operating hours**. Set the venue's open and
close time for each day, mark any closed days, and monitoring runs only inside
those windows — plus a buffer either side, because staff arrive before opening
and are still clearing up afterwards.

This is worth doing even on a free data source: it stops alerts firing at 3am
when nobody is on site. On a metered API it is the single biggest saving.

The panel shows what the schedule adds up to:

> Mon–Thu 12pm–10pm, Fri–Sat 12pm–11pm, Sun 12pm–10pm, plus 30 min before and
> 30 min after. That is 79 hours a week out of 168 — 47% of the polling a
> round-the-clock setup would do.

Times are wall-clock in the venue's time zone, so they keep meaning the same
thing across a daylight-saving change, and a closing time earlier than the
opening time is treated as after midnight.

If a lightning alert is still active when the venue closes, Storm Watch posts
**"Monitoring paused"** rather than letting the alert decay into an all clear
derived from no data — an all clear nobody checked is worse than none. The
dashboard says the same thing instead of showing a misleading green banner.

> Lightning detection networks are an aid, not a guarantee. Keep your venue's
> severe weather policy as the authority and treat this as one input to it.

---

## Connecting Slack

Settings → **Slack**. Bot token is the better option: one token can post to any
channel the bot has been invited to, and Slack returns a precise reason when
something is wrong.

1. Go to <https://api.slack.com/apps> → **Create New App** → **From scratch**.
   Name it (e.g. "Storm Watch") and pick your workspace.
2. **OAuth & Permissions** → **Scopes** → **Bot Token Scopes** → add
   `chat:write`.
3. **Install to Workspace**, then copy the **Bot User OAuth Token** — it starts
   with `xoxb-`.
4. In Slack, open the channel you want alerts in and run
   `/invite @Storm Watch`. **A bot cannot post to a channel it is not in** —
   this is the single most common reason alerts do not arrive.
5. Paste the token into Storm Watch, set the channel, **Save**, then
   **Send a test message**.

For the channel, a channel ID is the most reliable: in Slack, open the channel,
click its name, and use **Copy channel ID** at the bottom. `#channel-name` also
works.

The token is encrypted before it is stored, is never sent back to the browser,
and is never written to the activity log.

**Incoming webhook** is offered as an alternative when creating an app is not
practical. The channel is then fixed by the webhook itself.

### What gets posted

- **Lightning warning** when a strike lands inside the alert radius, with the
  distance, direction, time, and how many strikes are in the window.
- **Updates** while a warning is running — on a timer, and when the storm
  closes in by more than the configured margin.
- **All clear** once the alert radius has been quiet for the all-clear period.
- **Watch** notices as a storm enters the wider watch radius (off by default).
- **Feed failures**, at most once an hour, when lightning data stops arriving.

`@here` and `@channel` mentions apply only to lightning warnings — never to
all-clear or test messages, so the room is not pinged to be told everything is
fine.

---

## Alert rules and the cooldown

Settings → **Alert rules**. Out of the box the behaviour is:

> **Alert** as soon as lightning strikes within **10 miles** of the venue.
> Then **stay silent** until there have been **30 minutes** with no further
> strikes within 10 miles, and post the **all clear**.
> One alert and one all clear per storm — nothing in between.

The settings screen prints that sentence back to you as you change the numbers,
so there is no guessing about what the configuration adds up to.

| Setting | Default | What it does |
|---|---|---|
| Alert radius | 10 mi | A strike inside this raises the alert |
| Watch radius | 20 mi | Early heads-up ring; notifications off by default |
| Display radius | 30 mi | What gets stored and drawn on the map |
| Cooldown | 30 min | Quiet minutes required before the all clear |
| Cooldown scope | Alert radius | Which strikes keep the cooldown running |
| Repeat the alert every | Off | Opt-in reminder while the hold is in force |
| Re-alert if closer by | Off | Opt-in update if the storm heads straight at you |
| Operating hours | Off | Monitor only while the venue is open (see below) |

Ten miles and thirty minutes are the common choices for outdoor venues:
lightning routinely strikes that far from its parent storm, and the thirty
minute hold is the widely used all-clear guidance. Set them to whatever your
own safety policy requires.

**The cooldown is what stops alert spam.** A storm throwing lightning for an
hour produces exactly one Slack message, not one per strike. Because the all
clear only follows a full quiet period, two alerts can never be closer together
than the cooldown you set.

**Cooldown scope** decides which strikes count as "no strikes". The default —
only those inside the alert radius — matches standard lightning-safety practice.
Widen it to the watch or display ring if you would rather a storm circling just
outside the alert radius keep the venue on hold instead of triggering an early
all clear.

If the alert radius falls quiet while a storm is still being tracked further
out, the all clear is still sent — staff who were told to go indoors are owed
the word that they can come back out — and the message says the storm is still
around.

The **Mute 30m** button on the dashboard suppresses Slack and email while
leaving the map and state live — for when you are already standing in the rain
watching it and do not need to be told again.

---

## Email alerts

Settings → **Email**. Two transports:

- **Server mail** — PHP's `mail()`. Zero configuration, but shared hosts are
  frequently treated as spam sources.
- **SMTP** — host, port, encryption, username and password. Worth the extra
  fields. Use the mailbox credentials from cPanel → **Email Accounts**.

Set the "from" address to a real mailbox on the same domain, or the host will
reject the message. Carrier SMS gateway addresses work as recipients if you
want texts.

---

## Wall displays and kiosks

Settings → **System** has a **Kiosk link** containing a token. Opening that URL
shows the dashboard without signing in — useful for a screen in an office or
guest services. Regenerate the token to revoke every kiosk link at once.

If you would rather the dashboard were simply public, turn on **Public
dashboard** on the same tab. Settings always require signing in.

## No cron jobs? Web cron instead

Some hosting plans do not offer cron. Settings → **System** shows a **Web cron
URL** with its own token. Point any external scheduler at it once a minute —
cron-job.org, UptimeRobot, or a machine you control:

```
https://your-site/api/cron.php?token=YOUR_TOKEN&job=tick
```

Add a second schedule with `&job=worker` if you are using the Blitzortung feed.

---

## Security

- Passwords are hashed with `password_hash`; sign-in attempts are throttled per
  IP.
- All state-changing requests require a CSRF token.
- The Slack token and SMTP password are encrypted at rest with the
  installation's app key, and the API never returns them.
- `config/`, `data/`, `src/`, `bin/` and `tests/` each carry their own deny-all
  `.htaccess` that does not depend on `mod_rewrite`, on top of the block in the
  root `.htaccess`. The app rewrites any of them that goes missing. In the
  document-root layout they are outside the web root entirely.
- Every page sends `X-Frame-Options`, `X-Content-Type-Options`,
  `Referrer-Policy` and `noindex`.

Serve the site over HTTPS. cPanel provisions free certificates through
**SSL/TLS Status**; there is no reason not to.

If `config/config.php` is ever exposed or the app key changes, re-enter the
Slack token and SMTP password — those are the only values that become
unreadable.

---

## Command line

```bash
php bin/stormwatch.php status        # health summary; exits non-zero if unhealthy
php bin/stormwatch.php test-slack    # post a test message
php bin/stormwatch.php test-source   # check the configured feed
php bin/stormwatch.php simulate 4    # store a strike 4 miles out
php bin/stormwatch.php get           # print every setting
php bin/stormwatch.php set alert_radius_mi 8
php bin/stormwatch.php passwd admin 'a-new-passphrase'
php bin/stormwatch.php prune         # drop expired strikes and log entries
```

`status` exits non-zero when the feed or cron is unhealthy, so it can be wired
into external monitoring.

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| "Scheduled task not running" on the dashboard | The cron jobs are missing or the PHP path is wrong. Run `php bin/tick.php -v` by hand to see the real error. |
| Slack test says `not_in_channel` | Invite the bot: `/invite @YourBot` in that channel. |
| Slack test says `channel_not_found` | Use the channel ID rather than the name. |
| Slack test says `missing_scope` | Add `chat:write` under OAuth & Permissions, then reinstall the app. |
| Blitzortung test cannot connect | The host blocks outbound port 3000. Switch to the browser relay or a REST feed. The test checks the port first and says so within a few seconds. |
| Blitzortung test seems to hang | It should now answer in under 5 seconds when the port is blocked, and within about 10 when it is open. If the button still spins, the browser gives up at 45 seconds and tells you — that pattern means the host is silently dropping the connection rather than refusing it, so use the browser relay. |
| "This month's API allowance is used up" | Exactly that. Raise the budget on the Data source tab, slow the quiet poll down, or narrow the operating hours. Polling resumes on its own next month. |
| Alerts stop overnight | Expected if operating hours are switched on. The dashboard says "Monitoring paused" and when it resumes. |
| No strikes appear at all | Often there is simply no lightning within 30 miles. Press **Simulate strike** to confirm the pipeline works. |
| Email never arrives | Switch to SMTP and use a real mailbox on the domain as the "from" address. |
| The map is blank but everything else works | Leaflet is loaded from a CDN your network blocks. See below. |
| Setup wizard says a folder is not writable | Set `config/` and `data/` to `0755` in File Manager. |
| Sign-in keeps saying "session expired" | The app has mis-detected where it is mounted. Set `base_path` in `config/config.php` to the folder, e.g. `'/stormwatch'`. |
| "Storm Watch needs Apache's mod_rewrite" | Exactly what it says. Ask your host to enable it, or point a document root at `public/` instead. |
| Links in Slack or email point at the wrong address | Set **Public address of this dashboard** on the System settings tab, including the subfolder. |
| URLs show `/public/` in them | An old bookmark. It redirects to the clean URL on its own; nothing to fix. |

**Vendoring Leaflet.** If your network blocks CDNs, download Leaflet 1.9.4 and
drop `leaflet.js`, `leaflet.css` and its `images/` folder into
`public/assets/vendor/leaflet/`. The app uses the local copy automatically when
it is present. The dashboard keeps working without a map either way — alerts,
statistics and the strike log are unaffected.

---

## Development

```bash
php tests/run.php     # unit tests: geo, LZW, alert state machine, cooldown, mount detection
./tests/smoke.sh      # end-to-end: installs into a scratch dir and drives real HTTP
php -S localhost:8000 -t public

# Serve it the way a subfolder install is served, to test that arrangement:
SW_MOUNT=/stormwatch php -S localhost:8000 -t . tests/router.php
# then browse http://localhost:8000/stormwatch/
```

`tests/router.php` exists because the built-in server ignores `.htaccess`. It
reproduces what Apache does in a subfolder — serving `/mount/x` from `public/x`
while reporting `SCRIPT_NAME` with the extra `/public` segment — so the mount
detection is exercised for real rather than assumed.

`smoke.sh` stashes and restores any existing local installation, so it is safe
to run against a working checkout.

### Layout

```
bin/         cron entry points and the CLI
config/      config.php (generated) — database credentials and the app key
data/        SQLite database, locks, provider state; nothing web-servable
public/      the web root: pages, JSON API, assets
src/         application code, PSR-4 under the StormWatch namespace
tests/       unit tests and the end-to-end smoke test
```

The `src/` classes worth knowing: `AlertEngine` holds the state machine,
`Strikes` is the store and its de-duplication, `Runner` orchestrates the cron
jobs, `Settings` is the schema-driven configuration, and `Providers/` plus
`WebSocket/` contain the feed clients.

---

## Credits

Strike data from the [Blitzortung.org](https://www.blitzortung.org/) volunteer
network — if you rely on it, consider hosting a receiver. Map tiles ©
OpenStreetMap contributors and CARTO. Radar from
[RainViewer](https://www.rainviewer.com/) or the Iowa Environmental Mesonet.
Maps rendered with [Leaflet](https://leafletjs.com/).
