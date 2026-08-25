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
    { tab: $('tab-code'), panel: $('panel-code') },
    { tab: $('tab-git'), panel: $('panel-git') }
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

  // Fetching a slow site can legitimately take a while, but not forever. Without
  // a deadline a stalled request leaves the spinner running with no way back.
  var TIMEOUT_MS = 45000;
  var inflight = null;

  function send(form, spinner, body) {
    hideError();
    busy(form, spinner, true);

    if (inflight) {
      inflight.abort();
    }

    var controller = ('AbortController' in window) ? new AbortController() : null;
    inflight = controller;

    var timer = window.setTimeout(function () {
      if (controller) controller.abort();
    }, TIMEOUT_MS);

    var options = {
      method: 'POST',
      body: body,
      headers: { 'Accept': 'application/json' }
    };
    if (controller) options.signal = controller.signal;

    fetch('api/analyze.php', options)
      .then(function (res) {
        return res.json().catch(function () {
          throw new Error('The server sent something that was not a result. It may have run out of time.');
        });
      })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'Something went wrong.');
        render(data);
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') {
          showError('That took longer than ' + Math.round(TIMEOUT_MS / 1000) +
                    ' seconds, so it was stopped. The site may be slow or refusing to answer. Try again, or use the code tab.');
        } else {
          showError((err && err.message) || String(err));
        }
        results.hidden = true;
      })
      .then(function () {
        window.clearTimeout(timer);
        if (inflight === controller) inflight = null;
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
    if ($('crawl').checked) body.append('crawl', '1');
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

  $('form-git').addEventListener('submit', function (e) {
    e.preventDefault();
    var value = $('gitlog').value;
    if (!value.trim()) { showError('Paste the output of git log first.'); return; }
    var body = new FormData();
    body.append('mode', 'git');
    body.append('log', value);
    send(this, $('spin-git'), body);
  });

  // Click the command to select it — it is there to be copied.
  var command = $('git-command');
  if (command) {
    command.addEventListener('click', function () {
      var range = document.createRange();
      range.selectNodeContents(command);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    });
  }

  // ------------------------------------------------------------------ render

  function band(score) {
    if (score >= 70) return 'is-ai';
    if (score >= 55) return 'is-mixed';
    if (score >= 42) return 'is-unknown';
    return 'is-human';
  }

  // Counters read past a thousand the same way they do in the admin panel, and
  // for the same reason: "1.2k" is the answer, "1,247" is four more characters
  // saying nothing extra. Rounds down, so a count never overstates itself, and
  // drops the decimal once the leading part hits double figures. Kept in step
  // with Num::compact() in lib/Num.php — if one changes, change both.
  var UNITS = [[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'k']];

  function compact(n) {
    n = Math.floor(Number(n) || 0);
    var abs = Math.abs(n);
    if (abs < 1000) return String(n);

    var sign = n < 0 ? '-' : '';
    for (var i = 0; i < UNITS.length; i++) {
      var divisor = UNITS[i][0];
      if (abs < divisor) continue;

      var tenths = Math.floor(abs / (divisor / 10));
      var whole = Math.floor(tenths / 10);
      var frac = tenths % 10;
      return whole < 10 && frac > 0
        ? sign + whole + '.' + frac + UNITS[i][1]
        : sign + whole + UNITS[i][1];
    }
    return String(n);
  }

  // The exact figure is never thrown away — it goes in the title, one hover
  // away, wherever a shortened one is shown.
  function exact(n) {
    return String(Math.floor(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
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

    var counts = compact(data.counts.converging) + ' converging signal' + (data.counts.converging === 1 ? '' : 's');
    if (data.stats && data.stats.pages > 1) counts = compact(data.stats.pages) + ' pages · ' + counts;
    if (data.counts.human) counts += ' · ' + compact(data.counts.human) + ' pointing the other way';
    $('r-counts').textContent = counts;

    renderShot(data);
    renderSignals(data.signals);
    renderPages(data);
    renderTrend(data);
    renderNotes(data);

    var cert = $('r-cert');
    cert.href = 'api/certificate.php?p=' + encodeURIComponent(data.cert.payload) +
                '&s=' + encodeURIComponent(data.cert.sig);

    results.hidden = false;
    results.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  var shotTimer = null;

  // A renderer queues a page it has not seen before and answers the first ask
  // with a placeholder, which reaches us as a 202 and reaches the <img> as a
  // failure to load. So a miss is a reason to come back, two or three times,
  // spaced out — and then to stop, because the report is complete without it.
  var SHOT_WAITS = [2500, 4000, 6000];

  function renderShot(data) {
    var fig = $('r-shot');
    var img = $('r-shot-img');
    var status = $('r-shot-status');

    if (shotTimer) {
      window.clearTimeout(shotTimer);
      shotTimer = null;
    }
    fig.className = 'shot';
    img.removeAttribute('src');
    img.alt = '';

    var shot = data.snapshot;
    if (!shot || !shot.url) {
      fig.hidden = true;
      return;
    }

    img.alt = 'The front page of ' + data.target;
    status.textContent = 'Rendering the front page…';
    // Two different disclosures, because they are two different facts: a
    // service outside this site was told the address, or nobody was.
    $('r-shot-caption').textContent = shot.hosted
      ? 'The front page as ' + shot.provider + ' rendered it, passed through this site so your ' +
        'browser never asks them for it. It is there to show you what was read. Nothing in the ' +
        'reading below is scored on it.'
      : 'The front page, rendered by ' + shot.provider + ' — no outside service was told the ' +
        'address. It is there to show you what was read. Nothing in the reading below is scored on it.';
    fig.hidden = false;

    var tries = 0;
    img.onload = function () { fig.className = 'shot is-ready'; };
    img.onerror = function () {
      if (tries < SHOT_WAITS.length) {
        var wait = SHOT_WAITS[tries++];
        status.textContent = 'Still rendering…';
        shotTimer = window.setTimeout(function () { img.src = shot.url + '&r=' + tries; }, wait);
        return;
      }
      fig.className = 'shot is-empty';
      $('r-shot-caption').textContent = '';
      status.textContent = 'No picture this time. The reading below does not depend on one.';
    };
    img.src = shot.url;
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

      if (s.occurrences > 1) {
        var badge = el('span', 'occurrences', '\u00d7' + compact(s.occurrences));
        badge.title = 'found ' + exact(s.occurrences) + ' times; repeated findings weigh more';
        summary.appendChild(badge);
      }

      var body = el('div', 'signal-body');
      body.appendChild(el('p', null, s.detail));

      var excerpts = s.excerpts && s.excerpts.length ? s.excerpts : null;
      if (excerpts) {
        var list = el('ul', 'excerpts');
        excerpts.forEach(function (item) {
          list.appendChild(renderExcerpt(item));
        });
        body.appendChild(list);
      } else if (s.evidence && s.evidence.length) {
        // A report from an older version of the API: strings, no surroundings.
        var flat = el('ul', 'excerpts');
        s.evidence.forEach(function (line) {
          flat.appendChild(el('li', null, line));
        });
        body.appendChild(flat);
      }

      wrap.appendChild(body);
      host.appendChild(wrap);
    });
  }

  // One piece of evidence: what was matched, where, how often, and the code
  // it was sitting in. The surroundings are the point — a line on its own is
  // something to take on trust, and this page is built not to be taken on trust.
  function renderExcerpt(item) {
    var li = el('li');
    li.appendChild(el('span', 'excerpt-text', item.text));

    var bits = [];
    if (item.source) bits.push(item.source);
    if (item.line) bits.push('line ' + item.line);
    if (item.count > 1) bits.push('\u00d7' + compact(item.count));
    if (bits.length) {
      li.appendChild(el('span', 'excerpt-where', bits.join(' \u00b7 ')));
    }

    if (item.context && item.context.length) {
      var pre = el('pre', 'excerpt-context');
      var code = el('code');
      item.context.forEach(function (row) {
        var line = el('span', 'ctx-line' + (row.match ? ' is-match' : ''));
        line.appendChild(el('span', 'ctx-n', row.n == null ? '' : String(row.n)));
        line.appendChild(el('span', 'ctx-code', row.code === '' ? '\u00a0' : row.code));
        code.appendChild(line);
      });
      pre.appendChild(code);
      li.appendChild(pre);
    }
    return li;
  }

  function renderPages(data) {
    var host = $('r-pages');
    var list = $('r-pages-list');
    var pages = (data.stats && data.stats.perPage) || [];

    list.textContent = '';
    if (pages.length < 2) {
      host.hidden = true;
      return;
    }

    pages.forEach(function (p) {
      var li = el('li');
      var path = p.url;
      try { path = new URL(p.url).pathname || '/'; } catch (e) { /* keep the full url */ }

      li.appendChild(el('span', 'page-path', path));
      li.appendChild(el('span', 'page-words', p.words + ' words'));

      var score = el('span', 'page-score ' + band(p.score), p.score + '%');
      li.appendChild(score);
      list.appendChild(li);
    });
    host.hidden = false;
  }

  var SVG_NS = 'http://www.w3.org/2000/svg';

  function svgEl(tag, attrs) {
    var node = document.createElementNS(SVG_NS, tag);
    for (var key in attrs) node.setAttribute(key, attrs[key]);
    return node;
  }

  // Git mode only: added/removed lines per section of the history, oldest
  // first, as a pair of bars per bucket rising and falling from a baseline.
  function renderTrend(data) {
    var host = $('r-trend');
    var svg = $('r-trend-svg');
    var trend = data.stats && data.stats.trend;

    if (!trend || trend.length < 2) {
      host.hidden = true;
      return;
    }

    while (svg.firstChild) svg.removeChild(svg.firstChild);

    var W = 400, H = 90, mid = H / 2, pad = 4;
    var n = trend.length;
    var slot = W / n;
    var barW = Math.max(slot - 1, 0.5);

    var max = 1;
    trend.forEach(function (p) { max = Math.max(max, p.added, p.removed); });

    svg.appendChild(svgEl('line', { x1: 0, x2: W, y1: mid, y2: mid, class: 'trend-baseline' }));

    trend.forEach(function (p, i) {
      var x = i * slot;
      var addedH = (p.added / max) * (mid - pad);
      var removedH = (p.removed / max) * (mid - pad);

      if (addedH > 0.5) {
        svg.appendChild(svgEl('rect', {
          x: x, y: mid - addedH, width: barW, height: addedH, class: 'trend-added'
        }));
      }
      if (removedH > 0.5) {
        svg.appendChild(svgEl('rect', {
          x: x, y: mid, width: barW, height: removedH, class: 'trend-removed'
        }));
      }
    });

    host.hidden = false;
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
