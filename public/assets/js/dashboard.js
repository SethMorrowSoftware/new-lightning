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
    inFlight: null,
    failures: 0,
    clockOffset: 0,
    // What the forecast card is showing. Sent with every poll so the server
    // can skip re-sending a forecast that has not moved.
    forecastStamp: (boot.forecast && boot.forecast.stamp) || ''
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
    // Furthest band tracks the basemap, for the same contrast reason as the rings.
    return basemap.displayRing;
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

  /* All three are CARTO's free public tiles: same attribution, no account.
     The rings have to be redrawn to suit, because a colour picked to glow on
     Dark Matter is nearly invisible on a pale one. */
  var BASEMAPS = {
    dark: {
      url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
      displayRing: '#6FE3FF', ringWeight: 1, ringOpacity: 0.5, background: '#0B1020'
    },
    muted: {
      url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
      displayRing: '#0FA3C7', ringWeight: 2, ringOpacity: 0.75, background: '#E8E4DC'
    },
    light: {
      url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
      displayRing: '#0B7A96', ringWeight: 2, ringOpacity: 0.85, background: '#EAEAEA'
    }
  };
  var basemap = BASEMAPS[boot.mapStyle] || BASEMAPS.muted;

  // Tiles load a moment after the panel paints; match the gap to the map so it
  // is not a dark hole on a pale basemap. Also keep the legend honest — its
  // furthest swatch has to be the colour the ring is actually drawn in.
  var mapEl = el('map');
  if (mapEl) mapEl.style.background = basemap.background;
  var ringSwatch = el('ringSwatch');
  if (ringSwatch) ringSwatch.style.background = basemap.displayRing;

  /* The container keeps whatever pale colour the basemap would have been, so
     the message needs to bring its own dark surface with it — painted in the
     dimmest grey on a near-white rectangle it was barely readable. */
  function showMapUnavailable(reason) {
    var container = el('map');
    if (!container) return;
    container.style.background = 'transparent';
    container.innerHTML = '<div class="map-unavailable"><p><strong>' + escapeHtml(reason) + '</strong>'
      + 'Alerts, statistics and the strike log are unaffected — they do not come from the map.</p></div>';
  }

  if (!hasLeaflet) {
    showMapUnavailable('The map could not be loaded.');
  } else try {
    map = L.map('map', { zoomControl: true, attributionControl: true })
      .setView([boot.venue.lat, boot.venue.lon], boot.mapZoom);

    L.tileLayer(basemap.url, {
      attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
      subdomains: 'abcd',
      maxZoom: 19
    }).addTo(map);

    [
      { mi: boot.radii.alert, color: '#FF4D5E' },
      { mi: boot.radii.watch, color: '#FFB627' },
      { mi: boot.radii.display, color: basemap.displayRing }
    ].forEach(function (ring) {
      if (!ring.mi) return;
      L.circle([boot.venue.lat, boot.venue.lon], {
        radius: ring.mi * MI_TO_M,
        color: ring.color,
        // A hairline at half opacity disappears against a pale basemap.
        weight: basemap.ringWeight,
        opacity: basemap.ringOpacity,
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

    /* Keep the configured rings in view on first paint.

       This must not go through a circle's getBounds(): Leaflet computes those
       bounds by projecting through the map the layer belongs to, so asking a
       circle that was never added to one throws, and the throw lands here —
       after the tiles, rings and venue marker have been drawn, but before the
       first poll. The map looked fine and nothing else on the page ever
       updated. toBounds() is plain geometry on the coordinate and needs no
       map at all. */
    map.fitBounds(
      L.latLng(boot.venue.lat, boot.venue.lon).toBounds(boot.radii.display * MI_TO_M * 2),
      { padding: [12, 12] }
    );
  } catch (mapError) {
    /* The map is the one part of this page that is allowed to fail; the alert
       state is not. Losing the map must never stop the polling that tells an
       operator whether it is safe to be outside. */
    hasLeaflet = false;
    map = null;
    showMapUnavailable('The map could not be drawn.');
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

  // Radar is decoration over the top of the map; it gets the same treatment.
  try { initRadar(); } catch (radarError) { radarLayer = null; }

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
    // Keep the strike time with the layer so expiry can work from the markers
    // themselves rather than from the capped strike list.
    state.markers[strike.id] = { marker: marker, ts: strike.ts };
  }

  /* Expiry works off the server's clock, not the browser's. A kiosk mini-PC
     with a dead CMOS battery can sit hours out, and reading its clock would
     either clear a live storm off the map or never clear anything at all. */
  function serverNow() {
    return Math.floor(Date.now() / 1000) + state.clockOffset;
  }

  /* Walk the markers, not the strike list: the list is capped, and a squall
     line overruns that cap easily. Anything trimmed off the end would keep its
     marker on the map for ever, so the map slowly fills with strikes that
     happened hours ago and the rings stop meaning anything. */
  function expireMarkers() {
    var cutoff = serverNow() - (boot.markerTtlMinutes * 60);
    Object.keys(state.markers).forEach(function (id) {
      var entry = state.markers[id];
      if (!entry || entry.ts >= cutoff) return;
      if (entry.marker && map) map.removeLayer(entry.marker);
      delete state.markers[id];
    });
    state.strikes = state.strikes.filter(function (s) { return s.ts >= cutoff; });
  }

  /* The cap on the strike list is a display limit, not a reason to leave
     markers behind. Drop the markers of anything it discards. */
  function trimStrikes(limit) {
    if (state.strikes.length <= limit) return;
    var dropped = state.strikes.splice(limit);
    dropped.forEach(function (strike) {
      var entry = state.markers[strike.id];
      if (entry && entry.marker && map) map.removeLayer(entry.marker);
      delete state.markers[strike.id];
    });
  }

  function renderLog() {
    var list = el('logList');
    if (!list) return;
    if (!state.strikes.length) {
      list.innerHTML = '<div class="log-empty">No strikes recorded in the last '
        + boot.markerTtlMinutes + ' minutes.</div>';
      return;
    }
    var html = '';
    state.strikes.slice(0, 40).forEach(function (strike) {
      /* A button, not a div: these rows move the map, and reaching one used
         to require a mouse. */
      html += '<button type="button" class="log-row' + (strike.mi <= boot.radii.alert ? ' close' : '')
        + '" data-id="' + strike.id + '">'
        + '<span>' + fmtClock(strike.ts) + '</span>'
        + '<span>' + escapeHtml(strike.dir) + '</span>'
        + '<span class="dist">' + fmtDistance(strike.mi) + '</span>'
        + '</button>';
    });
    list.innerHTML = html;
  }

  var logList = el('logList');
  if (logList) logList.addEventListener('click', function (event) {
    var row = event.target.closest('.log-row');
    if (!row || !map) return;
    var entry = state.markers[row.getAttribute('data-id')];
    if (entry && entry.marker) {
      map.setView(entry.marker.getLatLng(), Math.max(map.getZoom(), 12));
      entry.marker.openPopup();
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
    },
    unknown: {
      icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.2a2.5 2.5 0 0 1 4.6 1.3c0 1.6-2.2 2-2.2 3.2"/><path d="M12 17h.01"/></svg>',
      title: 'Alert state unknown'
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
    var allClearTile = el('statAllClearTile');
    var holding = data.state.level === 'warning' && data.state.all_clear_at;
    if (holding) {
      var remaining = data.state.all_clear_at - data.server_time;
      allClear.textContent = remaining > 0 ? fmtDuration(remaining) : 'due';
    } else {
      allClear.textContent = '—';
    }
    /* While a hold is running this is the question staff are actually being
       asked, so it stops being the fourth of four identical tiles. */
    if (allClearTile) allClearTile.className = 'stat' + (holding ? ' is-primary' : '');

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

    /* Being configured is not the same as working. A revoked token, or a bot
       removed from its channel, leaves the switch on — and a green tick here
       over a channel that has not delivered anything for weeks is how nobody
       finds out until the storm. */
    var delivery = source.delivery || {};
    var broken = [];
    if (source.slack_ready && delivery.slack && !delivery.slack.ok) broken.push('slack');
    if (source.email_ready && delivery.email && !delivery.email.ok) broken.push('email');

    if (broken.length) {
      el('notifyDot').className = 'status-dot err';
      var names = broken.map(function (c) { return c === 'slack' ? 'Slack' : 'Email'; });
      el('notifyText').textContent = names.join(' and ') + ' rejected the last alert.'
        + (broken.length === 1 ? ' ' + delivery[broken[0]].message : '');
      return;
    }

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

  // ---------- forecast (National Weather Service) ----------

  /* Drawn from a cache the server keeps, never from api.weather.gov directly:
     a wall display polling every ten seconds must not become ten-second
     polling of a free public API, and the page must not wait on one either.

     Everything in here is wrapped so that it cannot throw into applyState().
     The forecast is context; the alert banner, the map and the strike log are
     the reason this page exists, and a bad character in a forecast sentence
     does not get to take them down. */

  var WX_ICONS = {
    sun: '<circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6"/>',
    moon: '<path d="M20 14.5A8.2 8.2 0 0 1 9.5 4 8.2 8.2 0 1 0 20 14.5z"/>',
    cloud: '<path d="M17.5 18H7a4.2 4.2 0 0 1-.5-8.4A5.6 5.6 0 0 1 17.4 10a4 4 0 0 1 .1 8z"/>',
    partly: '<circle cx="8" cy="8" r="3"/><path d="M8 2.6v1.6M2.6 8h1.6M4.4 4.4l1.1 1.1M11.6 4.4l-1.1 1.1"/><path d="M18 19.5h-7.6a3.6 3.6 0 0 1-.4-7.2 4.8 4.8 0 0 1 9.1.4 3.4 3.4 0 0 1-1.1 6.8z"/>',
    partlyNight: '<path d="M11.4 3.2a5.2 5.2 0 0 0 5.8 6.6"/><path d="M18 19.5h-7.6a3.6 3.6 0 0 1-.4-7.2 4.8 4.8 0 0 1 9.1.4 3.4 3.4 0 0 1-1.1 6.8z"/>',
    rain: '<path d="M17.5 15.5H7a4.2 4.2 0 0 1-.5-8.4A5.6 5.6 0 0 1 17.4 7.5a4 4 0 0 1 .1 8z"/><path d="M8.5 18.4l-1 2.4M12.5 18.4l-1 2.4M16.5 18.4l-1 2.4"/>',
    storm: '<path d="M17.5 14.5H7A4.2 4.2 0 0 1 6.5 6 5.6 5.6 0 0 1 17.4 6.5a4 4 0 0 1 .1 8z"/><path d="M13 15.5l-3.6 4.2h3l-.7 3.3"/>',
    snow: '<path d="M17.5 14.5H7A4.2 4.2 0 0 1 6.5 6 5.6 5.6 0 0 1 17.4 6.5a4 4 0 0 1 .1 8z"/><path d="M9 18.5h.01M12 20.5h.01M15 18.5h.01M12 17.2h.01"/>',
    fog: '<path d="M17 11H7a4.2 4.2 0 0 1-.5-8.4A5.6 5.6 0 0 1 17.4 3a4 4 0 0 1-.4 8z"/><path d="M4 15h16M6 18.5h13M4 22h9"/>',
    wind: '<path d="M3 8.5h11a3 3 0 1 0-3-3M3 13h15a3 3 0 1 1-3 3M3 17.5h8"/>'
  };

  function wxIcon(text, isDay) {
    var t = String(text || '').toLowerCase();
    var body;
    if (/thunder|t-?storm|tstm|squall|tornado/.test(t)) body = WX_ICONS.storm;
    else if (/snow|sleet|flurr|freezing|wintry|blizzard|ice pellets/.test(t)) body = WX_ICONS.snow;
    else if (/rain|shower|drizzle/.test(t)) body = WX_ICONS.rain;
    else if (/fog|haze|smoke|mist/.test(t)) body = WX_ICONS.fog;
    else if (/wind|breezy|blustery/.test(t)) body = WX_ICONS.wind;
    // Before the plain cloud test: "Mostly Cloudy" is not overcast.
    else if (/partly|mostly (sunny|clear|cloudy)|scattered clouds/.test(t)) body = isDay ? WX_ICONS.partly : WX_ICONS.partlyNight;
    else if (/cloud|overcast/.test(t)) body = WX_ICONS.cloud;
    else body = isDay ? WX_ICONS.sun : WX_ICONS.moon;
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" '
      + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
  }

  function wxTime(ts, parts) {
    try {
      return new Date(ts * 1000).toLocaleTimeString([], Object.assign({ timeZone: boot.timezone }, parts));
    } catch (e) {
      return new Date(ts * 1000).toLocaleTimeString([], parts);
    }
  }

  /* An expiry inside the next few hours reads better as a bare clock time;
     one tomorrow morning needs the day or it is actively misleading. */
  function wxUntil(ts) {
    var clock = wxTime(ts, { hour: 'numeric', minute: '2-digit' });
    if (ts - serverNow() < 18 * 3600) return clock;
    var day;
    try {
      day = new Date(ts * 1000).toLocaleDateString([], { weekday: 'short', timeZone: boot.timezone });
    } catch (e) {
      day = new Date(ts * 1000).toLocaleDateString([], { weekday: 'short' });
    }
    return day + ' ' + clock;
  }

  /* Severity as the service publishes it, corrected by the word people
     actually react to. A "warning" means it is happening or about to; a
     "watch" means conditions are right for it. Staff read those two words
     long before they read a severity field. */
  function wxAlertRank(alert) {
    var event = String(alert.event || '').toLowerCase();
    var severity = String(alert.severity || '').toLowerCase();
    if (severity === 'extreme' || /tornado/.test(event)) return 'high';
    if (severity === 'severe' || /warning/.test(event)) return 'high';
    if (severity === 'moderate' || /watch/.test(event)) return 'mid';
    return 'low';
  }

  function renderForecastAlerts(alerts) {
    var box = el('wxAlerts');
    if (!box) return false;
    if (!alerts || !alerts.length) {
      box.innerHTML = '';
      box.hidden = true;
      return false;
    }

    var worst = 'low';
    var html = '';
    alerts.forEach(function (alert) {
      var rank = wxAlertRank(alert);
      if (rank === 'high' || (rank === 'mid' && worst === 'low')) worst = rank;
      var when = [];
      if (alert.onset && alert.onset > serverNow()) when.push('from ' + wxUntil(alert.onset));
      if (alert.expires) when.push('until ' + wxUntil(alert.expires));
      html += '<div class="wx-alert ' + rank + '">'
        + '<span class="ev">' + escapeHtml(alert.event) + '</span>'
        + (when.length ? '<span class="wh">' + escapeHtml(when.join(', ')) + '</span>' : '')
        + (alert.area ? '<span class="ar">' + escapeHtml(alert.area) + '</span>' : '')
        + '</div>';
    });

    box.innerHTML = html;
    box.hidden = false;
    return worst === 'high';
  }

  function renderForecastHours(hours) {
    var box = el('wxHours');
    if (!box) return;
    if (!hours || !hours.length) {
      box.innerHTML = '';
      box.hidden = true;
      return;
    }
    var html = '';
    hours.forEach(function (hour, index) {
      var pop = (hour.pop === null || hour.pop === undefined) ? '' : hour.pop + '%';
      html += '<div class="wx-hour' + (hour.storm ? ' storm' : '') + '" title="'
        + escapeHtml(hour.short || '') + '">'
        + '<span class="h">' + escapeHtml(index === 0 ? 'Now' : wxTime(hour.start, { hour: 'numeric' })) + '</span>'
        + '<span class="i">' + wxIcon(hour.short, hour.day) + '</span>'
        + '<span class="t">' + (hour.temp === null || hour.temp === undefined ? '—' : hour.temp + '°') + '</span>'
        + '<span class="p">' + escapeHtml(pop) + '</span>'
        + '</div>';
    });
    box.innerHTML = html;
    box.hidden = false;
  }

  function renderForecastPeriods(periods) {
    var box = el('wxPeriods');
    if (!box) return;
    if (!periods || !periods.length) {
      box.innerHTML = '';
      box.hidden = true;
      return;
    }
    var html = '';
    periods.forEach(function (period) {
      var temp = (period.temp === null || period.temp === undefined)
        ? '—'
        : period.temp + '°' + (period.unit || '');
      var pop = (period.pop === null || period.pop === undefined || period.pop === 0)
        ? ''
        : '<span class="pp">' + period.pop + '% precip</span>';
      html += '<div class="wx-period' + (period.storm ? ' storm' : '') + '" title="'
        + escapeHtml(period.detail || period.short || '') + '">'
        + '<div class="nm">' + escapeHtml(period.name || '') + '</div>'
        + '<div class="hd"><span class="i">' + wxIcon(period.short, period.day) + '</span>'
        + '<span class="tp">' + escapeHtml(temp) + '</span></div>'
        + '<div class="sf">' + escapeHtml(period.short || '') + '</div>'
        + pop
        + '</div>';
    });
    box.innerHTML = html;
    box.hidden = false;
  }

  function renderForecast(forecast) {
    var card = el('wxCard');
    if (!card || !forecast) return;

    var place = el('wxPlace');
    if (place) place.textContent = forecast.place ? ' — ' + forecast.place : '';

    var stormy = renderForecastAlerts(forecast.alerts);
    renderForecastHours(forecast.hours);
    renderForecastPeriods(forecast.periods);

    /* How old it is, said plainly. A card that quietly stopped updating three
       hours ago — still showing this morning's "clear" — is worse than no card
       at all on a screen people make outdoor-safety decisions in front of. */
    var updated = el('wxUpdated');
    var age = forecast.updated_at ? serverNow() - forecast.updated_at : null;
    var stale = age === null || age > (forecast.stale_after || 3600);
    if (updated) {
      if (age === null) {
        updated.textContent = 'not fetched yet';
      } else {
        updated.textContent = 'updated ' + fmtDuration(Math.max(0, age)) + ' ago'
          + (stale ? ' — not refreshing' : '');
      }
      updated.className = stale ? 'muted wx-stale' : 'muted';
    }

    var note = el('wxNote');
    if (note) {
      if (!forecast.ok && forecast.message) {
        note.textContent = forecast.message;
        note.className = 'wx-note problem';
      } else if (stale) {
        note.textContent = 'This forecast is out of date. It is refreshed by the scheduled task — check the '
          + 'data source panel below if that has stopped running.';
        note.className = 'wx-note problem';
      } else {
        note.textContent = 'Forecast and warnings from the US National Weather Service.';
        note.className = 'wx-note';
      }
    }

    card.className = 'panel wx' + (stormy ? ' has-warning' : '') + (stale ? ' is-stale' : '');
  }

  function applyForecast(forecast) {
    try {
      renderForecast(forecast);
    } catch (e) {
      // Never let the weather card break the page it sits on top of.
      var note = el('wxNote');
      if (note) {
        note.textContent = 'The forecast could not be displayed.';
        note.className = 'wx-note problem';
      }
    }
  }

  if (boot.forecast) applyForecast(boot.forecast);

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

  wireButton('wxRefreshBtn', function () {
    return post('refresh_forecast').then(function (data) {
      if (data.forecast) {
        applyForecast(data.forecast);
        state.forecastStamp = data.forecast.stamp || state.forecastStamp;
      }
      // Success here is unremarkable — the card visibly changes — so only say
      // something when it did not work.
      if (!data.ok) toast(data.error || data.message || 'The forecast could not be refreshed.', 'info');
    });
  });

  wireButton('logClearBtn', function () {
    if (!window.confirm('Clear every stored strike? This also resets the alert state.')) {
      return Promise.resolve();
    }
    return post('clear_strikes').then(function (data) {
      if (map) {
        Object.keys(state.markers).forEach(function (id) {
          var entry = state.markers[id];
          if (entry && entry.marker) map.removeLayer(entry.marker);
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

  /* A poll that never answers is the worst failure this page has, because it
     looks exactly like a page that has nothing to report. Without a deadline
     the browser waits indefinitely and the placeholders stay up. Give up
     inside two refresh intervals and say what happened. */
  var POLL_TIMEOUT_MS = Math.max(20, Math.max(3, boot.refreshSeconds) * 2) * 1000;

  /* Returns { status, ok, text }, with the deadline covering the body as well
     as the headers.

     Disarming the timer when the headers land is not enough. A shared host
     whose PHP worker dies mid-response leaves the front end holding the
     connection open, so the headers arrive and the body never finishes. The
     read then waits for ever, the in-flight guard below never releases, and
     every later tick returns the same stuck promise — the page freezes on
     whatever it last knew, still showing a green badge, with nothing on it
     admitting it has stopped. That is the exact failure this deadline exists
     to prevent, so it stays armed until the last byte is in. */
  function fetchState(url) {
    var options = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };

    if (typeof AbortController === 'undefined') {
      return fetch(url, options).then(function (response) {
        return response.text().then(function (text) {
          return { status: response.status, ok: response.ok, text: text };
        });
      });
    }

    var controller = new AbortController();
    options.signal = controller.signal;
    var timedOut = false;
    var timer = setTimeout(function () { timedOut = true; controller.abort(); }, POLL_TIMEOUT_MS);

    var finish = function () { clearTimeout(timer); };
    var fail = function (error) {
      finish();
      if (timedOut || (error && error.name === 'AbortError')) {
        throw new Error('The server did not answer within '
          + Math.round(POLL_TIMEOUT_MS / 1000) + ' seconds.');
      }
      throw error;
    };

    return fetch(url, options).then(function (response) {
      return response.text().then(function (text) {
        finish();
        return { status: response.status, ok: response.ok, text: text };
      }, fail);
    }, fail);
  }

  /* A server error on shared hosting usually arrives as an HTML page rather
     than JSON. Quote the top of it: that line is normally the whole diagnosis,
     and it is the one thing the operator cannot get at from the browser. */
  function snippet(text) {
    var clean = String(text).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    if (clean === '') return '';
    return clean.length > 160 ? clean.slice(0, 160) + '…' : clean;
  }

  /* Say so in the places an operator is already looking. A dashboard that
     stops updating while still showing a green tick is worse than one that
     admits it is broken — this page is used to decide whether it is safe to
     be outside. */
  function reportPollFailure(detail) {
    el('modeBadge').className = 'mode-badge live-err';
    el('modeBadgeText').textContent = 'Dashboard not updating';
    el('sourceDot').className = 'status-dot err';
    el('sourceText').textContent = detail;

    if (state.level !== null) return;

    // Nothing has ever been read, so nothing on this page is known to be true.
    el('alertBanner').className = 'alert-banner unknown';
    el('alertIcon').innerHTML = BANNER.unknown.icon;
    el('alertT1').textContent = BANNER.unknown.title;
    el('alertT2').textContent = 'This page cannot read the alert state, so it cannot tell you whether '
      + 'there is lightning nearby. Slack and email alerts are sent by the server and do not '
      + 'depend on this page.';
    el('cronDot').className = 'status-dot err';
    el('cronText').textContent = 'Unknown — no answer from the server.';
    el('notifyDot').className = 'status-dot err';
    el('notifyText').textContent = 'Unknown — no answer from the server.';
    el('providerSub').textContent = '';
  }

  function applyState(data) {
    var isFirstLoad = state.maxId === 0;

    // Trust the server's clock over this machine's — see serverNow().
    if (data.server_time) {
      state.clockOffset = data.server_time - Math.floor(Date.now() / 1000);
    }

    data.strikes.forEach(function (strike) {
      plotStrike(strike, !isFirstLoad);
      state.strikes.unshift(strike);
    });
    if (data.strikes.length) {
      state.strikes.sort(function (a, b) { return b.ts - a.ts || b.id - a.id; });
      trimStrikes(300);
    }
    state.maxId = Math.max(state.maxId, data.max_id || 0);

    expireMarkers();
    renderState(data);
    renderStats(data);
    renderSource(data);
    // Only sent when it has changed; the stamp comes back either way so the
    // next poll can go on asking for nothing.
    if (data.forecast) applyForecast(data.forecast);
    if (typeof data.forecast_stamp === 'string') state.forecastStamp = data.forecast_stamp;
    // Every poll, not only the ones that brought something. Strikes age out on
    // a timer, and a log still listing a strike from two hours ago reads as a
    // storm that is still going.
    renderLog();
  }

  function poll() {
    // A slow poll must not have a second one stacked on top of it: that turns
    // one stalled request into a queue of them.
    if (state.inFlight) return state.inFlight;

    // stateUrl already carries a query string on a kiosk display, which is
    // authenticated by a token in the URL rather than by a session.
    var params = [];
    if (state.maxId) params.push('since_id=' + state.maxId);
    if (state.forecastStamp) params.push('forecast=' + encodeURIComponent(state.forecastStamp));
    var url = params.length
      ? boot.stateUrl + (boot.stateUrl.indexOf('?') === -1 ? '?' : '&') + params.join('&')
      : boot.stateUrl;
    var request = fetchState(url)
      .then(function (response) {
        if (response.status === 401) {
          window.location.href = boot.loginUrl || 'login.php';
          throw new Error('Signed out');
        }
        var text = response.text;
        var data = null;
        try { data = JSON.parse(text); } catch (e) { data = null; }

        if (!response.ok) {
          var reason = (data && data.error) ? data.error : snippet(text);
          throw new Error('The server answered HTTP ' + response.status
            + (reason ? ' — ' + reason : '.'));
        }
        if (data === null) {
          var head = snippet(text);
          throw new Error('The server\'s answer was not JSON'
            + (head ? ' — ' + head : '.'));
        }
        return data;
      })
      .then(function (data) {
        state.failures = 0;
        try {
          applyState(data);
        } catch (e) {
          // A rendering bug is a different problem from an unreachable server,
          // so do not let it be reported as one.
          throw new Error('The dashboard could not display the server\'s answer: '
            + (e && e.message ? e.message : e));
        }
      })
      .catch(function (error) {
        if (error && error.message === 'Signed out') return;
        state.failures += 1;
        var detail = (error && error.message) ? error.message : 'The request failed.';
        reportPollFailure(state.failures > 1
          ? detail + ' (' + state.failures + ' attempts in a row.)'
          : detail);
      });

    state.inFlight = request;
    var release = function () { state.inFlight = null; };
    request.then(release, release);
    return request;
  }

  /* Slow down when the tab is hidden — never stop.

     Browser notifications exist precisely for the tab nobody is looking at: a
     duty manager with the dashboard behind their email is the case the feature
     was built for. Stopping the poll on hide meant the alert that mattered was
     the one guaranteed not to be noticed, and the page then showed it as
     "just arrived" whenever they happened to switch back. A minute between
     polls in the background is a small cost against that. */
  var BACKGROUND_POLL_MS = 60000;

  function schedule() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    var interval = document.hidden
      ? Math.max(BACKGROUND_POLL_MS, Math.max(3, boot.refreshSeconds) * 1000)
      : Math.max(3, boot.refreshSeconds) * 1000;
    state.pollTimer = setInterval(poll, interval);
  }

  document.addEventListener('visibilitychange', function () {
    // Coming back to the tab should show current data, not the last background
    // poll's, so catch up immediately as well as restoring the fast cadence.
    if (!document.hidden) poll();
    schedule();
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
