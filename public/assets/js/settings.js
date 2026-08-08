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

  document.querySelectorAll('[data-test]').forEach(function (button) {
    button.addEventListener('click', function () {
      var action = button.getAttribute('data-test');
      var original = button.textContent;
      button.disabled = true;
      button.innerHTML = '<span class="spinner"></span> Testing…';

      var box = document.getElementById('testResult');
      if (box) {
        box.innerHTML = '<div class="notice">Save your changes first if you have edited anything — '
          + 'the test uses the settings currently stored.</div>';
      }

      fetch(config.actionUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
        body: JSON.stringify({ action: action })
      })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          renderResult(!!data.ok, data.message || data.error || 'No response.', data.detail);
        })
        .catch(function (error) {
          renderResult(false, 'The request failed: ' + error.message);
        })
        .then(function () {
          button.disabled = false;
          button.textContent = original;
        });
    });
  });
})();
