/* Storm Watch dashboard.
   Draws the map, polls the server for state, and keeps the banner, statistics
   and strike log in step with it. The server is the source of truth for the
   alert level — this file never decides on its own that something is an alert. */
(function () {
  'use strict';

  var boot = JSON.parse(document.getElementById('sw-boot').textContent);
  var MI_TO_M = 1609.344;
  var MI_TO_KM = 1.609344;
  var STORE = boot.storagePrefix || 'sw_';

  var state = {
    maxId: 0,
    level: null,
    strikes: [],           // newest first, capped
    markers: {},           // id -> leaflet marker
    notifPermission: (typeof Notification !== 'undefined') ? Notification.permission : 'denied',
    notifEnabled: localStorage.getItem(STORE + 'browser_notifications') === '1',
    lastAlertSignature: null,
    pollTimer: null,
    failures: 0
  };

  // ---------- helpers ----------

  function el(id) { return document.getElementById(id); }

  function fmtDistance(mi) {
    if (mi === null || mi === undefined) return '—';
    return boot.units === 'km'
      ? (mi * MI_TO_KM).toFixed(1) + ' km'
      : mi.toFixed(1) + ' mi';
  }

  function fmtClock(ts) {
    try {
      return new Date(ts * 1000).toLocaleTimeString([], {
        hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: boot.timezone
      });
    } catch (e) {
      return new Date(ts * 1000).toLocaleTimeString();
    }
  }

  function fmtDuration(seconds) {
    if (seconds === null || seconds === undefined || seconds < 0) return '—';
    if (seconds < 60) return seconds + 's';
    var m = Math.floor(seconds / 60);
    if (m < 60) return m + 'm';
    var h = Math.floor(m / 60);
    if (h < 24) return h + 'h ' + (m % 60) + 'm';
    return Math.floor(h / 24) + 'd';
  }

  function colourForDistance(mi) {
    if (mi <= boot.radii.alert) return '#FF4D5E';
    if (mi <= boot.radii.watch) return '#FFB627';
    return '#6FE3FF';
  }

  function toast(message, kind) {
    var stack = el('toastStack');
    if (!stack) return;
    var node = document.createElement('div');
    node.className = 'toast' + (kind ? ' ' + kind : '');
    var icon = kind === 'info' || kind === 'ok'
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/></svg>';
    var title = kind === 'info' || kind === 'ok' ? 'Storm Watch' : 'Lightning alert';
    node.innerHTML = icon + '<div><b>' + title + '</b><br>' + escapeHtml(message) + '</div>';
    stack.appendChild(node);
    setTimeout(function () {
      node.style.transition = 'opacity .4s';
      node.style.opacity = '0';
      setTimeout(function () { node.remove(); }, 400);
    }, 8000);
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function post(action, payload) {
    var body = Object.assign({ action: action }, payload || {});
    return fetch(boot.actionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrf },
      body: JSON.stringify(body)
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok && !data.error) { data.error = 'Request failed (HTTP ' + response.status + ').'; }
        return data;
      });
    });
  }

  // ---------- map ----------

  /* Leaflet comes from a CDN. If it is blocked or slow, the map is lost but
     the alert state, statistics and strike log still have to work — this is
     the page someone checks to decide whether to clear a midway. */
  var hasLeaflet = typeof L !== 'undefined' && L && typeof L.map === 'function';
  var map = null;

  if (!hasLeaflet) {
    var container = el('map');
    if (container) {
      container.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;'
        + 'height:100%;padding:24px;text-align:center;color:var(--text-lo);font-size:13px;line-height:1.6;">'
        + 'The map library could not be loaded, so the map is unavailable.<br>'
        + 'Alerts, statistics and the strike log below are unaffected.</div>';
    }
  } else {
    map = L.map('map', { zoomControl: true, attributionControl: true })
      .setView([boot.venue.lat, boot.venue.lon], boot.mapZoom);

    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
      subdomains: 'abcd',
      maxZoom: 19
    }).addTo(map);

    [
      { mi: boot.radii.alert, color: '#FF4D5E' },
      { mi: boot.radii.watch, color: '#FFB627' },
      { mi: boot.radii.display, color: '#6FE3FF' }
    ].forEach(function (ring) {
      if (!ring.mi) return;
      L.circle([boot.venue.lat, boot.venue.lon], {
        radius: ring.mi * MI_TO_M,
        color: ring.color,
        weight: 1,
        opacity: 0.5,
        fill: false,
        dashArray: '4 6',
        interactive: false
      }).addTo(map);
    });

    var venueIcon = L.divIcon({
      className: '',
      html: '<div style="width:16px;height:16px;border-radius:50%;background:#FFB627;'
          + 'box-shadow:0 0 0 6px rgba(255,182,39,0.18);border:2px solid #241A02;"></div>',
      iconSize: [16, 16],
      iconAnchor: [8, 8]
    });
    L.marker([boot.venue.lat, boot.venue.lon], { icon: venueIcon, zIndexOffset: 500 })
      .addTo(map)
      .bindPopup('<b>' + escapeHtml(boot.venue.name) + '</b>'
        + (boot.venue.address ? '<br>' + escapeHtml(boot.venue.address) : ''));

    // Keep the configured rings in view on first paint.
    map.fitBounds(
      L.circle([boot.venue.lat, boot.venue.lon], { radius: boot.radii.display * MI_TO_M }).getBounds(),
      { padding: [12, 12] }
    );
  }

  // ---------- radar overlay ----------

  var radarLayer = null;

  function setRadarOpacity(value) {
    boot.radar.opacity = value;
    if (radarLayer) radarLayer.setOpacity(value);
  }

  function initRadar() {
    if (!hasLeaflet || boot.radar.type === 'none') return;
    if (boot.radar.type === 'nexrad') {
      radarLayer = L.tileLayer(
        'https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q-900913/{z}/{x}/{y}.png',
        { opacity: boot.radar.opacity, attribution: 'NEXRAD © Iowa Environmental Mesonet', maxZoom: 15 }
      ).addTo(map);
      return;
    }
    if (boot.radar.type !== 'rainviewer') return;

    // RainViewer publishes a manifest of recent radar frames; use the newest.
    var apply = function () {
      fetch('https://api.rainviewer.com/public/weather-maps.json')
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var past = (data.radar && data.radar.past) || [];
          if (!past.length) return;
          var frame = past[past.length - 1];
          var url = (data.host || 'https://tilecache.rainviewer.com')
            + frame.path + '/256/{z}/{x}/{y}/2/1_1.png';
          var next = L.tileLayer(url, {
            opacity: boot.radar.opacity,
            attribution: 'Radar © RainViewer',
            maxZoom: 15
          }).addTo(map);
          if (radarLayer) {
            var old = radarLayer;
            setTimeout(function () { map.removeLayer(old); }, 500);
          }
          radarLayer = next;
        })
        .catch(function () { /* radar is decoration; never break the map over it */ });
    };
    apply();
    setInterval(apply, 5 * 60 * 1000);
  }

  initRadar();

  var opacitySlider = el('radarOpacity');
  if (opacitySlider) {
    opacitySlider.addEventListener('input', function () {
      setRadarOpacity(Number(opacitySlider.value) / 100);
    });
  }

  // ---------- strike rendering ----------

  function plotStrike(strike, animate) {
    if (!hasLeaflet || state.markers[strike.id]) return;
    var colour = colourForDistance(strike.mi);

    if (animate) {
      var flash = L.circleMarker([strike.lat, strike.lon], {
        radius: 3, color: colour, fillColor: colour, fillOpacity: 0.9, weight: 0, interactive: false
      }).addTo(map);
      var radius = 3;
      var opacity = 0.85;
      var grow = setInterval(function () {
        radius += 2.5;
        opacity -= 0.06;
        flash.setStyle({ opacity: Math.max(opacity, 0), fillOpacity: Math.max(opacity, 0) });
        flash.setRadius(radius);
        if (opacity <= 0) {
          clearInterval(grow);
          if (map.hasLayer(flash)) map.removeLayer(flash);
        }
      }, 30);
    }

    var icon = L.divIcon({
      className: '',
      html: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="' + colour
          + '" stroke-width="2"><path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z" fill="' + colour
          + '" fill-opacity="0.25"/></svg>',
      iconSize: [16, 16],
      iconAnchor: [8, 8]
    });
    var marker = L.marker([strike.lat, strike.lon], { icon: icon }).addTo(map);
    marker.bindPopup('<b>' + fmtDistance(strike.mi) + '</b> ' + escapeHtml(strike.dir)
      + ' of the venue<br>' + fmtClock(strike.ts));
    state.markers[strike.id] = marker;
  }

  function expireMarkers() {
    var cutoff = Math.floor(Date.now() / 1000) - (boot.markerTtlMinutes * 60);
    state.strikes.forEach(function (strike) {
      if (strike.ts >= cutoff) return;
      var marker = state.markers[strike.id];
      if (marker && map) {
        map.removeLayer(marker);
      }
      delete state.markers[strike.id];
    });
    state.strikes = state.strikes.filter(function (s) { return s.ts >= cutoff; });
  }

  function renderLog() {
    var list = el('logList');
    if (!state.strikes.length) {
      list.innerHTML = '<div class="log-empty">No strikes recorded in the last '
        + boot.markerTtlMinutes + ' minutes.</div>';
      return;
    }
    var html = '';
    state.strikes.slice(0, 40).forEach(function (strike) {
      html += '<div class="log-row' + (strike.mi <= boot.radii.alert ? ' close' : '')
        + '" data-id="' + strike.id + '">'
        + '<span>' + fmtClock(strike.ts) + '</span>'
        + '<span>' + escapeHtml(strike.dir) + '</span>'
        + '<span class="dist">' + fmtDistance(strike.mi) + '</span>'
        + '</div>';
    });
    list.innerHTML = html;
  }

  el('logList').addEventListener('click', function (event) {
    var row = event.target.closest('.log-row');
    if (!row || !map) return;
    var marker = state.markers[row.getAttribute('data-id')];
    if (marker) {
      map.setView(marker.getLatLng(), Math.max(map.getZoom(), 12));
      marker.openPopup();
    }
  });

  // ---------- banner and status ----------

  var BANNER = {
    clear: {
      icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 6L9 17l-5-5"/></svg>',
      title: 'All clear'
    },
    watch: {
      icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>',
      title: 'Storm approaching'
    },
    warning: {
      icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/></svg>',
      title: 'Lightning nearby'
    }
  };

  function renderState(data) {
    var level = data.state.level;
    var banner = el('alertBanner');
    var preset = BANNER[level] || BANNER.clear;

    banner.className = 'alert-banner ' + level;
    el('alertIcon').innerHTML = preset.icon;

    if (level === 'warning') {
      el('alertT1').textContent = 'Lightning within ' + fmtDistance(boot.radii.alert);
      el('alertT2').textContent = data.state.nearest_mi !== null
        ? 'Nearest strike ' + fmtDistance(data.state.nearest_mi)
          + ' from the venue. Move activities indoors and hold for the all clear.'
        : 'Move activities indoors and hold for the all clear.';
    } else if (level === 'watch') {
      el('alertT1').textContent = 'Storm within ' + fmtDistance(boot.radii.watch);
      el('alertT2').textContent = data.state.nearest_mi !== null
        ? 'Nearest strike ' + fmtDistance(data.state.nearest_mi)
          + ' from the venue — outside the alert radius, but worth watching.'
        : 'Lightning detected inside the watch radius.';
    } else {
      el('alertT1').textContent = 'All clear';
      el('alertT2').textContent = 'No lightning detected within '
        + fmtDistance(boot.radii.alert) + ' of the venue.';
    }

    if (data.state.muted_until) {
      el('alertT2').textContent += ' Alerts are muted until '
        + fmtClock(data.state.muted_until) + '.';
    }

    var muteBtn = el('muteBtn');
    var unmuteBtn = el('unmuteBtn');
    if (muteBtn) muteBtn.style.display = data.state.muted_until ? 'none' : '';
    if (unmuteBtn) unmuteBtn.style.display = data.state.muted_until ? '' : 'none';

    // Announce a level change once, not on every poll.
    var signature = level + ':' + (data.state.since || 0);
    if (state.level !== null && signature !== state.lastAlertSignature && level !== state.level) {
      if (level === 'warning') {
        var text = 'Lightning ' + fmtDistance(data.state.nearest_mi) + ' from '
          + boot.venue.name + ' — inside the ' + fmtDistance(boot.radii.alert) + ' alert radius.';
        toast(text);
        notify('Lightning alert — ' + boot.venue.name, text);
      } else if (level === 'clear' && state.level === 'warning') {
        toast('All clear — no lightning within ' + fmtDistance(boot.radii.alert)
          + ' for ' + boot.allClearMinutes + ' minutes.', 'ok');
        notify('All clear — ' + boot.venue.name,
          'No lightning within ' + fmtDistance(boot.radii.alert) + ' of the venue.');
      }
    }
    state.lastAlertSignature = signature;
    state.level = level;
  }

  function renderStats(data) {
    el('statTotal').textContent = data.stats.total;
    var close = el('statClose');
    close.textContent = data.stats.close;
    close.className = 'n' + (data.stats.close > 0 ? ' danger' : '');
    el('statNearest').textContent = data.stats.nearest_mi !== null
      ? fmtDistance(data.stats.nearest_mi) : '—';

    var allClear = el('statAllClear');
    if (data.state.level === 'warning' && data.state.all_clear_at) {
      var remaining = data.state.all_clear_at - data.server_time;
      allClear.textContent = remaining > 0 ? fmtDuration(remaining) : 'due';
    } else {
      allClear.textContent = '—';
    }

    el('strikeCount').textContent = state.strikes.length + ' strike'
      + (state.strikes.length === 1 ? '' : 's') + ' shown';

    var age = el('lastStrikeAge');
    if (data.stats.last_struck_at) {
      age.textContent = 'last strike ' + fmtDuration(data.server_time - data.stats.last_struck_at) + ' ago';
    } else {
      age.textContent = '';
    }
  }

  function renderSource(data) {
    var source = data.source;
    var badge = el('modeBadge');
    var badgeText = el('modeBadgeText');
    var healthy = source.source_healthy && source.cron_healthy;

    // Outside operating hours nothing is being fetched, so saying "all clear"
    // would be a reassurance nobody checked. Say what is actually true.
    if (source.monitoring === false) {
      badge.className = 'mode-badge';
      badgeText.textContent = 'Monitoring paused';
      el('sourceDot').className = 'status-dot warn';
      el('sourceText').textContent = 'Outside operating hours — lightning is not being tracked'
        + (source.next_start_text ? '. Resumes ' + source.next_start_text + '.' : '.');
      el('cronDot').className = 'status-dot ' + (source.cron_healthy ? 'ok' : 'err');
      el('cronText').textContent = source.schedule_summary || '';
      el('providerLabel').textContent = PROVIDER_LABELS[source.provider] || source.provider;
      el('providerSub').textContent = 'Paused until the venue reopens.';
      var channelsPaused = [];
      if (source.slack_ready) channelsPaused.push('Slack');
      if (source.email_ready) channelsPaused.push('Email');
      el('notifyDot').className = 'status-dot ' + (channelsPaused.length ? 'ok' : 'warn');
      el('notifyText').textContent = channelsPaused.length
        ? 'Server alerts go to ' + channelsPaused.join(' and ') + ' once monitoring resumes.'
        : 'No server alert channel is switched on.';
      return;
    }

    badge.className = 'mode-badge ' + (healthy ? 'live-ok' : (source.cron_healthy ? '' : 'live-err'));
    badgeText.textContent = healthy
      ? 'Live — ' + source.provider
      : (source.cron_healthy ? 'Live — feed problem' : 'Scheduled task not running');

    el('providerLabel').textContent = PROVIDER_LABELS[source.provider] || source.provider;
    el('providerSub').textContent = PROVIDER_HINTS[source.provider] || '';

    el('sourceDot').className = 'status-dot ' + (source.source_healthy ? 'ok' : 'err');
    el('sourceText').textContent = source.source_message;

    el('cronDot').className = 'status-dot ' + (source.cron_healthy ? 'ok' : 'err');
    el('cronText').textContent = source.cron_healthy
      ? 'Scheduled task ran ' + fmtDuration(source.cron_age_seconds) + ' ago.'
      : (source.cron_last_run
          ? 'Scheduled task last ran ' + fmtDuration(source.cron_age_seconds)
            + ' ago — it should run every minute. Check the cron job.'
          : 'The scheduled task has never run. Alerts will not fire until the cron job is installed.');

    var channels = [];
    if (source.slack_ready) channels.push('Slack');
    if (source.email_ready) channels.push('Email');
    el('notifyDot').className = 'status-dot ' + (channels.length ? 'ok' : 'warn');
    el('notifyText').textContent = channels.length
      ? 'Server alerts go to ' + channels.join(' and ') + '.'
      : 'No server alert channel is switched on — only this page will show alerts.';
  }

  var PROVIDER_LABELS = {
    blitzortung: 'Blitzortung live feed',
    rest: 'REST endpoint',
    relay: 'Browser relay',
    simulator: 'Simulator (demo data)'
  };
  var PROVIDER_HINTS = {
    blitzortung: 'Streamed by the server worker each minute.',
    rest: 'Polled by the server on a schedule.',
    relay: 'Fed by this browser tab — keep it open.',
    simulator: 'Generating a drifting storm cell for testing. Switch to a live source before relying on alerts.'
  };

  // ---------- notifications ----------

  function notify(title, body) {
    if (!state.notifEnabled || state.notifPermission !== 'granted') return;
    try {
      new Notification(title, { body: body, tag: 'stormwatch-alert', renotify: true });
    } catch (e) { /* some browsers refuse outside a user gesture */ }
  }

  var notifToggle = el('notifToggle');
  if (notifToggle) {
    notifToggle.checked = state.notifEnabled && state.notifPermission === 'granted';
    notifToggle.addEventListener('change', function () {
      if (!notifToggle.checked) {
        state.notifEnabled = false;
        localStorage.setItem(STORE + 'browser_notifications', '0');
        return;
      }
      if (typeof Notification === 'undefined') {
        toast('This browser does not support notifications.', 'info');
        notifToggle.checked = false;
        return;
      }
      Notification.requestPermission().then(function (permission) {
        state.notifPermission = permission;
        if (permission === 'granted') {
          state.notifEnabled = true;
          localStorage.setItem(STORE + 'browser_notifications', '1');
          toast('Browser notifications are on for this device.', 'ok');
        } else {
          notifToggle.checked = false;
          toast('Notification permission was not granted.', 'info');
        }
      });
    });
  }

  // ---------- actions ----------

  function wireButton(id, handler) {
    var button = el(id);
    if (!button) return;
    button.addEventListener('click', function () {
      button.disabled = true;
      Promise.resolve(handler())
        .catch(function (error) { toast(error.message || 'That did not work.', 'info'); })
        .then(function () { button.disabled = false; });
    });
  }

  wireButton('simulateBtn', function () {
    return post('simulate').then(function (data) {
      toast(data.message || data.error, data.ok ? 'ok' : 'info');
      return poll();
    });
  });

  wireButton('muteBtn', function () {
    return post('mute', { minutes: 30 }).then(function (data) {
      toast(data.message || data.error, 'info');
      return poll();
    });
  });

  wireButton('unmuteBtn', function () {
    return post('unmute').then(function (data) {
      toast(data.message || data.error, 'ok');
      return poll();
    });
  });

  wireButton('runTickBtn', function () {
    return post('run_tick').then(function (data) {
      toast(data.message || data.error, data.ok ? 'ok' : 'info');
      return poll();
    });
  });

  wireButton('logClearBtn', function () {
    if (!window.confirm('Clear every stored strike? This also resets the alert state.')) {
      return Promise.resolve();
    }
    return post('clear_strikes').then(function (data) {
      if (map) {
        Object.keys(state.markers).forEach(function (id) {
          if (state.markers[id]) map.removeLayer(state.markers[id]);
        });
      }
      state.markers = {};
      state.strikes = [];
      state.maxId = 0;
      renderLog();
      toast(data.message || data.error, 'info');
      return poll();
    });
  });

  // ---------- polling ----------

  function poll() {
    var url = boot.stateUrl + (state.maxId ? '?since_id=' + state.maxId : '');
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) {
        if (response.status === 401) {
          window.location.href = boot.loginUrl || 'login.php';
          throw new Error('Signed out');
        }
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (data) {
        state.failures = 0;
        var isFirstLoad = state.maxId === 0;

        data.strikes.forEach(function (strike) {
          plotStrike(strike, !isFirstLoad);
          state.strikes.unshift(strike);
        });
        if (data.strikes.length) {
          state.strikes.sort(function (a, b) { return b.ts - a.ts || b.id - a.id; });
          if (state.strikes.length > 300) state.strikes.length = 300;
        }
        state.maxId = Math.max(state.maxId, data.max_id || 0);

        expireMarkers();
        renderState(data);
        renderStats(data);
        renderSource(data);
        if (data.strikes.length || isFirstLoad) renderLog();
      })
      .catch(function (error) {
        if (error && error.message === 'Signed out') return;
        state.failures += 1;
        if (state.failures === 3) {
          el('modeBadge').className = 'mode-badge live-err';
          el('modeBadgeText').textContent = 'Dashboard offline';
          el('sourceDot').className = 'status-dot err';
          el('sourceText').textContent = 'The dashboard cannot reach the server. Alerts may still be firing server-side.';
        }
      });
  }

  function schedule() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    state.pollTimer = setInterval(poll, Math.max(3, boot.refreshSeconds) * 1000);
  }

  // Pause polling when the tab is hidden; catch up as soon as it returns.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
    } else {
      poll();
      schedule();
    }
  });

  // ---------- clock ----------

  var clock = el('clock');
  if (clock) {
    var tick = function () {
      try {
        clock.textContent = new Date().toLocaleTimeString([], { timeZone: boot.timezone });
      } catch (e) {
        clock.textContent = new Date().toLocaleTimeString();
      }
    };
    tick();
    setInterval(tick, 1000);
  }

  // Expose a tiny surface for the relay script.
  window.StormWatch = {
    boot: boot,
    poll: poll,
    toast: toast,
    setSourceStatus: function (ok, message) {
      el('sourceDot').className = 'status-dot ' + (ok ? 'ok' : 'err');
      el('sourceText').textContent = message;
    }
  };

  poll();
  schedule();
})();
