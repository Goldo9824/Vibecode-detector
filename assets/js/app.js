/*
 * No framework, no build step. The server does the analysis; this file moves
 * the result onto the page and nothing else.
 */
(function () {
  'use strict';

  var $ = function (id) { return document.getElementById(id); };

  var errorBox = $('error');
  var results = $('results');

  // ------------------------------------------------------------------- tabs

  var tabs = [
    { tab: $('tab-url'), panel: $('panel-url') },
    { tab: $('tab-code'), panel: $('panel-code') }
  ];

  function selectTab(index) {
    tabs.forEach(function (t, i) {
      var on = i === index;
      t.tab.setAttribute('aria-selected', on ? 'true' : 'false');
      t.panel.hidden = !on;
    });
    hideError();
  }

  tabs.forEach(function (t, i) {
    t.tab.addEventListener('click', function () { selectTab(i); });
    t.tab.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var next = (i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
      selectTab(next);
      tabs[next].tab.focus();
    });
  });

  // ------------------------------------------------------------------ submit

  function hideError() {
    errorBox.hidden = true;
    errorBox.textContent = '';
  }

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = false;
  }

  function busy(form, spinner, on) {
    var button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = on;
    spinner.hidden = !on;
  }

  function send(form, spinner, body) {
    hideError();
    busy(form, spinner, true);

    fetch('api/analyze.php', {
      method: 'POST',
      body: body,
      headers: { 'Accept': 'application/json' }
    })
      .then(function (res) {
        return res.json().catch(function () {
          throw new Error('The server sent something that was not a result. It may have timed out.');
        });
      })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'Something went wrong.');
        render(data);
      })
      .catch(function (err) {
        showError(err.message || String(err));
        results.hidden = true;
      })
      .then(function () {
        busy(form, spinner, false);
      });
  }

  $('form-url').addEventListener('submit', function (e) {
    e.preventDefault();
    var value = $('url').value.trim();
    if (!value) { showError('Put a URL in the box first.'); return; }
    var body = new FormData();
    body.append('mode', 'url');
    body.append('url', value);
    send(this, $('spin-url'), body);
  });

  $('form-code').addEventListener('submit', function (e) {
    e.preventDefault();
    var value = $('code').value;
    if (!value.trim()) { showError('Paste some code first.'); return; }
    var body = new FormData();
    body.append('mode', 'code');
    body.append('code', value);
    send(this, $('spin-code'), body);
  });

  // ------------------------------------------------------------------ render

  function band(score) {
    if (score >= 70) return 'is-ai';
    if (score >= 55) return 'is-mixed';
    if (score >= 42) return 'is-unknown';
    return 'is-human';
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function render(data) {
    var klass = band(data.score);

    $('r-subject').textContent = data.target;

    var score = $('r-score');
    score.className = 'score ' + klass;
    score.textContent = data.score;
    score.appendChild(el('span', null, '%'));

    var meter = $('r-meter');
    meter.className = 'meter-fill ' + klass;
    // Painted after a frame so the width animates from zero on every run.
    meter.style.width = '0';
    requestAnimationFrame(function () { meter.style.width = data.score + '%'; });

    $('r-verdict').textContent = data.verdict.label;
    $('r-summary').textContent = data.verdict.summary;
    $('r-confidence').textContent = 'Confidence: ' + data.confidence.label;

    var counts = data.counts.converging + ' converging signal' + (data.counts.converging === 1 ? '' : 's');
    if (data.counts.human) counts += ' · ' + data.counts.human + ' pointing the other way';
    $('r-counts').textContent = counts;

    renderSignals(data.signals);
    renderNotes(data);

    var cert = $('r-cert');
    cert.href = 'api/certificate.php?p=' + encodeURIComponent(data.cert.payload) +
                '&s=' + encodeURIComponent(data.cert.sig);

    results.hidden = false;
    results.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function renderSignals(signals) {
    var host = $('r-signals');
    host.textContent = '';

    if (!signals.length) {
      host.appendChild(el('p', null,
        'Nothing fired in either direction. That is not a clean bill of health — it usually means there was too little to read, or that whatever was there has been through a formatter.'));
      return;
    }

    signals.forEach(function (s) {
      var wrap = el('details', 'signal' + (s.direction === 'human' ? ' is-human' : ''));
      var summary = el('summary');

      summary.appendChild(el('span', 'signal-label', s.label));

      var tags = el('span', 'signal-tags');
      tags.appendChild(el('span', 'strength', s.strength));
      tags.appendChild(document.createTextNode(' · ' + s.categoryLabel));
      summary.appendChild(tags);

      wrap.appendChild(summary);

      var body = el('div', 'signal-body');
      body.appendChild(el('p', null, s.detail));

      if (s.evidence && s.evidence.length) {
        var list = el('ul', 'excerpts');
        s.evidence.forEach(function (line) {
          list.appendChild(el('li', null, line));
        });
        body.appendChild(list);
      }

      wrap.appendChild(body);
      host.appendChild(wrap);
    });
  }

  function renderNotes(data) {
    var list = $('r-notes');
    list.textContent = '';

    var notes = (data.notes || []).slice();
    notes.push(data.confidence.reason);

    if (data.counts.fingerprint) {
      notes.push('A platform fingerprint identifies the builder outright. Everything else in this report is secondary to that.');
    } else if (data.counts.converging === 0 && data.counts.ai > 0) {
      notes.push('Only aesthetic cues fired. That is a reason to look closer, never a conclusion — the score is held below the line on purpose.');
    }

    notes.forEach(function (text) {
      list.appendChild(el('li', null, text));
    });
  }
}());
