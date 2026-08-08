/* Settings screen: show only the fields that apply to the current selection,
   and run the connection tests without leaving the page. Everything here is
   progressive enhancement — the forms submit and save fine without it. */
(function () {
  'use strict';

  var config = JSON.parse(document.getElementById('sw-settings').textContent);

  // ---- show the panels that belong to the selected provider ----
  var providerSelect = document.getElementById('provider');
  if (providerSelect) {
    var syncProvider = function () {
      var current = providerSelect.value;
      document.querySelectorAll('[data-provider]').forEach(function (panel) {
        var applies = panel.getAttribute('data-provider').split(' ').indexOf(current) !== -1;
        panel.style.display = applies ? '' : 'none';
      });
      var warning = document.getElementById('simulatorWarning');
      if (warning) warning.style.display = current === 'simulator' ? '' : 'none';
    };
    providerSelect.addEventListener('change', syncProvider);
    syncProvider();
  }

  // ---- Slack: bot token vs webhook ----
  var slackMode = document.getElementById('slack_mode');
  if (slackMode) {
    var botFields = ['slack_bot_token', 'slack_channel'];
    var webhookFields = ['slack_webhook_url'];
    var syncSlack = function () {
      var isBot = slackMode.value === 'bot';
      botFields.concat(webhookFields).forEach(function (id) {
        var input = document.getElementById(id);
        if (!input) return;
        var field = input.closest('.field');
        if (!field) return;
        var wanted = isBot ? botFields.indexOf(id) !== -1 : webhookFields.indexOf(id) !== -1;
        field.style.display = wanted ? '' : 'none';
      });
    };
    slackMode.addEventListener('change', syncSlack);
    syncSlack();
  }

  /* ---- Known providers. Filling these in by hand from the API docs is
         where a REST integration usually goes wrong. ---- */
  var PRESETS = {
    xweather_flash: {
      label: 'Xweather — lightning flash (included with every plan)',
      endpoint: 'https://data.api.xweather.com/lightning/flash/closest'
        + '?p={lat},{lon}&radius={radius_mi}miles&limit=100&format=json',
      auth: 'client_pair',
      authName: 'client_id',
      secretName: 'client_secret',
      root: 'response',
      lat: 'loc.lat',
      lon: 'loc.long',
      time: 'ob.timestamp',
      timeFormat: 'epoch_s',
      idle: 120,
      active: 60,
      budget: 15000,
      maxRadius: 25,
      window: 5,
      note: 'The endpoint every Xweather plan includes, the free tier among them. Put your '
        + '<b>client ID</b> in the key field and your <b>client secret</b> in the secret field — '
        + 'Xweather issues a pair, not a single key.<br><br>It carries two limits, and both have been '
        + 'filled in for you: requests are capped at a <b>25 mile radius</b>, and it only serves the '
        + '<b>last 5 minutes</b> of strikes. The idle poll is therefore 120s, not 300s — polling at '
        + 'the full 5 minutes would leave no margin, and a late cron run would silently miss strikes. '
        + 'Press <b>Test this source</b> after saving: it reports the first record returned, so any '
        + 'mismatch in the field mapping is obvious.'
    },
    xweather_enterprise: {
      label: 'Xweather — lightning (needs the Lightning Enterprise add-on)',
      endpoint: 'https://data.api.xweather.com/lightning/closest'
        + '?p={lat},{lon}&radius={radius_mi}miles&filter=all&limit=100&format=json',
      auth: 'client_pair',
      authName: 'client_id',
      secretName: 'client_secret',
      root: 'response',
      lat: 'loc.lat',
      lon: 'loc.long',
      time: 'ob.timestamp',
      timeFormat: 'epoch_s',
      idle: 120,
      active: 60,
      budget: 15000,
      maxRadius: 0,
      window: 0,
      note: '<b>Only pick this if your account carries the Lightning Enterprise add-on.</b> Without '
        + 'it the endpoint answers <code>HTTP 401 insufficient_scope</code> — the credentials are '
        + 'accepted, the plan simply does not cover this path. Use the flash option above instead. '
        + 'What the add-on buys is no 25 mile cap and history beyond the last 5 minutes, which is why '
        + 'the radius limit and poll window are left unset here.'
    }
  };

  var presetSelect = document.getElementById('restPreset');
  if (presetSelect) {
    presetSelect.addEventListener('change', function () {
      var preset = PRESETS[presetSelect.value];
      var note = document.getElementById('presetNote');
      if (!preset) {
        if (note) note.style.display = 'none';
        return;
      }
      var set = function (id, value) {
        var input = document.getElementById(id);
        if (input) input.value = value;
      };
      set('rest_endpoint', preset.endpoint);
      set('rest_auth_mode', preset.auth);
      set('rest_auth_name', preset.authName);
      set('rest_auth_secret_name', preset.secretName);
      set('rest_map_root', preset.root);
      set('rest_map_lat', preset.lat);
      set('rest_map_lon', preset.lon);
      set('rest_map_time', preset.time);
      set('rest_time_format', preset.timeFormat);
      set('rest_poll_seconds', preset.idle);
      set('rest_active_poll_seconds', preset.active);
      set('api_monthly_budget', preset.budget);
      set('rest_max_radius_mi', preset.maxRadius);
      set('rest_data_window_minutes', preset.window);

      var mode = document.getElementById('rest_auth_mode');
      if (mode) mode.dispatchEvent(new Event('change'));

      if (note) {
        note.style.display = '';
        note.innerHTML = '<b>' + escapeHtml(preset.label) + '.</b> ' + preset.note
          + '<br><br>Your credentials were not touched. Nothing is saved until you press '
          + '<b>Save data source</b>.';
      }
      renderCostProjection();
    });
  }

  // ---- Auth: the second credential only exists for ID+secret providers ----
  var authMode = document.getElementById('rest_auth_mode');
  if (authMode) {
    var syncAuth = function () {
      var pair = authMode.value === 'client_pair';
      var secretField = document.getElementById('restSecretField');
      var secretName = document.getElementById('secretNameField');
      if (secretField) secretField.style.display = pair ? '' : 'none';
      if (secretName) secretName.style.display = pair ? '' : 'none';
      var keyLabel = document.getElementById('restKeyLabel');
      if (keyLabel) keyLabel.textContent = pair ? 'Client ID' : 'API key';
    };
    authMode.addEventListener('change', syncAuth);
    syncAuth();
  }

  /* ---- Will this polling pattern fit the plan? Answer before it is saved,
         not at the end of the month. ---- */
  function renderCostProjection() {
    var box = document.getElementById('costProjection');
    if (!box) return;

    var num = function (id, fallback) {
      var input = document.getElementById(id);
      var value = input ? parseFloat(input.value) : NaN;
      return isNaN(value) || value <= 0 ? fallback : value;
    };
    var idle = num('rest_poll_seconds', 300);
    var active = num('rest_active_poll_seconds', 60);
    var budget = num('api_monthly_budget', 0);

    var hoursPerWeek = config.scheduleEnabled ? (config.monitoredHoursPerWeek || 168) : 168;
    var monitoredHoursPerMonth = hoursPerWeek * (365 / 12 / 7);
    var stormHours = 10; // a working assumption, stated in the text
    var quietHours = Math.max(0, monitoredHoursPerMonth - stormHours);
    var monthly = Math.round((quietHours * 3600 / idle) + (stormHours * 3600 / active));

    var fits = budget === 0 || monthly <= budget;
    var text = '<b>Projected use:</b> about <b>' + monthly.toLocaleString() + '</b> accesses a month'
      + ' — polling every ' + Math.round(idle) + 's while quiet and every ' + Math.round(active)
      + 's during a storm, across ' + Math.round(monitoredHoursPerMonth) + ' monitored hours'
      + (config.scheduleEnabled ? ' (your operating hours)' : ' (round the clock)')
      + ', assuming ' + stormHours + ' hours of storm activity.';

    if (budget > 0) {
      text += fits
        ? '<br>That fits inside your ' + budget.toLocaleString() + ' allowance.'
        : '<br><span style="color:var(--danger);">That exceeds your ' + budget.toLocaleString()
          + ' allowance. Slow the quiet poll down, or restrict the operating hours on the Alert rules tab.</span>';
    }

    box.className = 'notice' + (fits ? '' : ' err');
    box.innerHTML = text;
  }

  ['rest_poll_seconds', 'rest_active_poll_seconds', 'api_monthly_budget'].forEach(function (id) {
    var input = document.getElementById(id);
    if (!input) return;
    input.addEventListener('input', renderCostProjection);
    input.addEventListener('change', renderCostProjection);
  });
  renderCostProjection();

  // ---- Schedule grid is only relevant when the schedule is switched on ----
  var scheduleToggle = document.getElementById('monitor_schedule_enabled');
  if (scheduleToggle) {
    var syncSchedule = function () {
      var fields = document.getElementById('scheduleFields');
      if (fields) fields.style.opacity = scheduleToggle.checked ? '1' : '0.55';
    };
    scheduleToggle.addEventListener('change', syncSchedule);
    syncSchedule();
  }

  // ---- Email: SMTP fields only when SMTP is selected ----
  var transport = document.getElementById('email_transport');
  if (transport) {
    var syncTransport = function () {
      var smtp = transport.value === 'smtp';
      ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass'].forEach(function (id) {
        var input = document.getElementById(id);
        if (!input) return;
        var field = input.closest('.field');
        if (field) field.style.display = smtp ? '' : 'none';
      });
    };
    transport.addEventListener('change', syncTransport);
    syncTransport();
  }

  /* ---- Cooldown: say back, in plain words, what the numbers add up to.
         Radii and minutes are easy to mis-set, and the cost of getting them
         wrong is either alert spam or an all clear that comes too early. ---- */
  var summaryBox = document.getElementById('cooldownSummary');
  if (summaryBox) {
    var num = function (id, fallback) {
      var input = document.getElementById(id);
      var value = input ? parseFloat(input.value) : NaN;
      return isNaN(value) ? fallback : value;
    };
    var plural = function (n, one, many) { return n === 1 ? one : many; };

    var renderSummary = function () {
      var alertR = num('alert_radius_mi', 10);
      var watchR = num('watch_radius_mi', 20);
      var displayR = num('display_radius_mi', 30);
      var minutes = num('all_clear_minutes', 30);
      var scopeEl = document.getElementById('cooldown_scope');
      var scope = scopeEl ? scopeEl.value : 'alert';
      var repeat = num('realert_minutes', 0);
      var closer = num('closer_delta_mi', 0);

      var scopeRadius = scope === 'display' ? displayR : (scope === 'watch' ? watchR : alertR);
      var scopeText = scope === 'alert'
        ? 'no further strikes within ' + alertR + ' mi'
        : (scope === 'watch'
            ? 'no strikes within ' + watchR + ' mi'
            : 'no strikes anywhere in the ' + displayR + ' mi tracked area');

      var lines = [
        '<b>Alert</b> as soon as lightning strikes within <b>' + alertR + ' mi</b> of the venue.',
        'Then <b>stay silent</b> until there have been <b>' + minutes + ' ' + plural(minutes, 'minute', 'minutes')
          + '</b> with ' + scopeText + ', and post the <b>all clear</b>.'
      ];

      if (repeat > 0 || closer > 0) {
        var extras = [];
        if (repeat > 0) extras.push('repeat the alert every ' + repeat + ' ' + plural(repeat, 'minute', 'minutes'));
        if (closer > 0) extras.push('send an update if the storm closes in by ' + closer + ' mi');
        lines.push('While the hold is running, also ' + extras.join(', and ') + '.');
      } else {
        lines.push('That is one alert and one all clear per storm — nothing in between.');
      }

      if (scope !== 'alert' && scopeRadius <= alertR) {
        lines.push('<b>Note:</b> this cooldown scope has no effect — its ring is not larger than the alert radius.');
      }

      summaryBox.innerHTML = lines.join('<br>');
    };

    ['alert_radius_mi', 'watch_radius_mi', 'display_radius_mi', 'all_clear_minutes',
     'cooldown_scope', 'realert_minutes', 'closer_delta_mi'].forEach(function (id) {
      var input = document.getElementById(id);
      if (!input) return;
      input.addEventListener('input', renderSummary);
      input.addEventListener('change', renderSummary);
    });
    renderSummary();
  }

  // ---- connection tests ----
  function renderResult(ok, message, detail) {
    var box = document.getElementById('testResult');
    if (!box) return;
    var html = '<div class="notice ' + (ok ? 'ok' : 'err') + '">'
      + '<b>' + (ok ? 'Success.' : 'That did not work.') + '</b> ' + escapeHtml(message);
    if (detail && Object.keys(detail).length) {
      var sample = detail.sample;
      if (sample !== undefined && sample !== null) {
        html += '<br><br>First record returned:<br><code>'
          + escapeHtml(JSON.stringify(sample).slice(0, 600)) + '</code>';
      }
      if (detail.hint) html += '<br><br>' + escapeHtml(detail.hint);
    }
    html += '</div>';
    box.innerHTML = html;
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // A connection test talks to a remote service, so it is slow by nature.
  // Count up while it runs and give up on our own terms: a button that spins
  // forever is indistinguishable from a broken page.
  var TEST_TIMEOUT_MS = 45000;

  document.querySelectorAll('[data-test]').forEach(function (button) {
    button.addEventListener('click', function () {
      var action = button.getAttribute('data-test');
      var original = button.textContent;
      var box = document.getElementById('testResult');
      var started = Date.now();
      button.disabled = true;

      var tick = setInterval(function () {
        var seconds = Math.round((Date.now() - started) / 1000);
        button.innerHTML = '<span class="spinner"></span> Testing… ' + seconds + 's';
      }, 1000);
      button.innerHTML = '<span class="spinner"></span> Testing…';

      if (box) {
        box.innerHTML = '<div class="notice">Contacting the data source. This can take up to '
          + Math.round(TEST_TIMEOUT_MS / 1000) + ' seconds — a host that blocks the connection is '
          + 'slow to say so.<br>If you have just edited anything, save first: the test uses the '
          + 'settings currently stored.</div>';
      }

      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timer = setTimeout(function () {
        if (controller) controller.abort();
      }, TEST_TIMEOUT_MS);

      var options = {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
        body: JSON.stringify({ action: action })
      };
      if (controller) options.signal = controller.signal;

      fetch(config.actionUrl, options)
        .then(function (response) {
          return response.text().then(function (text) {
            try {
              return JSON.parse(text);
            } catch (e) {
              // A PHP fatal or a proxy timeout page, not JSON.
              return {
                ok: false,
                message: 'The server returned an unreadable response (HTTP ' + response.status + '). '
                  + 'If this keeps happening the test is probably exceeding the host\'s time limit.',
                detail: { hint: text.slice(0, 300) }
              };
            }
          });
        })
        .then(function (data) {
          renderResult(!!data.ok, data.message || data.error || 'No response.', data.detail);
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') {
            renderResult(false, 'The test did not finish within '
              + Math.round(TEST_TIMEOUT_MS / 1000) + ' seconds and was stopped. '
              + 'That usually means the host is silently dropping the connection rather than refusing it.',
              { hint: 'Try the "Browser relay" provider, which fetches the same data from a browser tab instead of the server.' });
          } else {
            renderResult(false, 'The request failed: ' + (error ? error.message : 'unknown error'));
          }
        })
        .then(function () {
          clearInterval(tick);
          clearTimeout(timer);
          button.disabled = false;
          button.textContent = original;
        });
    });
  });
})();
