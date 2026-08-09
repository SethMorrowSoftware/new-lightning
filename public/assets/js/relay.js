/* Browser relay for the Blitzortung feed.

   Some shared hosts will not open an outbound connection on port 3000, which
   is the only way to reach the Blitzortung stream. This script does it from
   the browser instead and posts the strikes back to the server, so the normal
   server-side alerting still applies.

   The trade-off is that a tab has to stay open somewhere — a back-office PC or
   the venue's wall display. The dashboard says so plainly when this mode is on. */
(function () {
  'use strict';

  var sw = window.StormWatch;
  if (!sw || !sw.boot.relay) return;

  var config = sw.boot.relay;
  var venue = sw.boot.venue;

  var socket = null;
  var serverIndex = 0;
  var reconnectTimer = null;
  var queue = [];
  var flushTimer = null;
  var stats = { received: 0, sent: 0, lastSent: null };

  // ---- LZW, matching the encoding the feed uses ----
  function lzwDecode(input) {
    if (!input) return '';
    var chars = Array.from(input);
    var dictionary = {};
    var currentChar = chars[0];
    var oldPhrase = currentChar;
    var out = [currentChar];
    var code = 256;

    for (var i = 1; i < chars.length; i++) {
      var currentCode = chars[i].charCodeAt(0);
      var phrase;
      if (currentCode < 256) {
        phrase = chars[i];
      } else if (Object.prototype.hasOwnProperty.call(dictionary, currentCode)) {
        phrase = dictionary[currentCode];
      } else {
        phrase = oldPhrase + currentChar;
      }
      out.push(phrase);
      currentChar = phrase.charAt(0);
      dictionary[code] = oldPhrase + currentChar;
      code += 1;
      oldPhrase = phrase;
    }
    return out.join('');
  }

  function haversineMi(lat1, lon1, lat2, lon2) {
    var R = 3958.8;
    var toRad = function (d) { return d * Math.PI / 180; };
    var dLat = toRad(lat2 - lat1);
    var dLon = toRad(lon2 - lon1);
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2)
      + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function serverUrl() {
    var servers = config.servers && config.servers.length ? config.servers : ['ws1', 'ws7', 'ws8'];
    return 'wss://' + servers[serverIndex % servers.length] + '.blitzortung.org:3000/';
  }

  function connect() {
    disconnect();
    var url = serverUrl();
    sw.setSourceStatus(false, 'Relay connecting to ' + url + '…');

    try {
      socket = new WebSocket(url);
    } catch (error) {
      sw.setSourceStatus(false, 'Relay could not open a WebSocket: ' + error.message);
      scheduleReconnect();
      return;
    }

    socket.onopen = function () {
      socket.send(config.initJson || '{"a":111}');
      sw.setSourceStatus(true, 'Relay connected — streaming live strikes and forwarding nearby ones.');
    };

    socket.onmessage = function (event) {
      var record;
      try {
        record = JSON.parse(lzwDecode(event.data));
      } catch (error) {
        return; // malformed frame; the feed has plenty of them
      }
      if (typeof record.lat !== 'number' || typeof record.lon !== 'number') return;

      stats.received += 1;
      var distance = haversineMi(venue.lat, venue.lon, record.lat, record.lon);
      if (distance > config.displayRadiusMi) return;

      queue.push({ lat: record.lat, lon: record.lon, time: record.time });
      if (queue.length >= 25) flush();
    };

    socket.onerror = function () {
      sw.setSourceStatus(false, 'Relay WebSocket error — trying another Blitzortung server.');
    };

    socket.onclose = function (event) {
      sw.setSourceStatus(false, 'Relay connection closed (code ' + event.code + ') — reconnecting…');
      scheduleReconnect();
    };
  }

  function scheduleReconnect() {
    clearTimeout(reconnectTimer);
    serverIndex += 1;
    reconnectTimer = setTimeout(connect, 5000);
  }

  function disconnect() {
    clearTimeout(reconnectTimer);
    if (!socket) return;
    socket.onclose = null;
    socket.onerror = null;
    try { socket.close(); } catch (error) { /* already gone */ }
    socket = null;
  }

  /* How often to check in when there is nothing to send. Without this the
     server has no way to tell a working relay during calm weather from a tab
     somebody closed — and it is the tab closing that stops the alerts. */
  var HEARTBEAT_MS = 60000;
  var MAX_QUEUE = 2000;

  /* Batch the posts. A busy cell can produce strikes faster than one request
     each would be reasonable, and the server de-duplicates anyway. */
  function flush() {
    var due = queue.length > 0
      || stats.lastSent === null
      || (Date.now() - stats.lastSent) >= HEARTBEAT_MS;
    if (!due) return;

    var batch = queue.splice(0, 200);

    fetch(config.ingestUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Ingest-Token': config.token },
      body: JSON.stringify({ strikes: batch })
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'unknown error');
        stats.sent += data.stored;
        stats.lastSent = Date.now();
        if (data.stored > 0) sw.poll();
      })
      .catch(function (error) {
        /* Put the batch back. A shared host answering 503 for a moment is
           routine, and these are the only copy of those strikes — dropping
           them loses the lightning nobody else was watching for. */
        if (batch.length) {
          queue = batch.concat(queue);
          if (queue.length > MAX_QUEUE) queue.length = MAX_QUEUE;
        }
        sw.setSourceStatus(false, 'Relay could not store strikes ('
          + (error && error.message ? error.message : 'no answer from the server')
          + '); holding ' + queue.length + ' and retrying.');
      });
  }

  flushTimer = setInterval(flush, 10000);
  flush();   // check in immediately, so the server knows the tab is here

  window.addEventListener('beforeunload', function () {
    clearInterval(flushTimer);
    disconnect();
  });

  connect();
})();
