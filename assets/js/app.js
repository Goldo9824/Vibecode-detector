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
    { tab: $('tab-auto'), panel: $('panel-auto') },
    { tab: $('tab-url'), panel: $('panel-url') },
    { tab: $('tab-repo'), panel: $('panel-repo') },
    { tab: $('tab-code'), panel: $('panel-code') },
    { tab: $('tab-git'), panel: $('panel-git') }
  ];

  // Tabs are addressed by id rather than by position, so adding one in the
  // markup does not silently repoint everything that referred to an index.
  function tabIndex(id) {
    for (var i = 0; i < tabs.length; i++) {
      if (tabs[i].tab && tabs[i].tab.id === id) return i;
    }
    return 0;
  }

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
    // The run button shows one thing at a time: an arrow, or that it is
    // running. Toggled here rather than in CSS because :has() is younger than
    // the browsers this has to work in.
    var mark = button && button.querySelector('.go-mark');
    if (mark) mark.hidden = on;
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

  /*
   * The reading settings.
   *
   * A panel of sliders that hangs off a small button inside the field, one
   * slider per thing this reading is allowed to open. It replaced a tickbox
   * that could say "one page" or "all fifty" and nothing in between, which is
   * the wrong shape for a control whose cost lands on somebody else.
   *
   * Auto mode carries both sliders, because which one matters is not decided
   * until the paste has been read. Sending the one that turns out not to
   * apply costs nothing: the endpoint ignores a parameter its chosen mode has
   * no use for.
   *
   * Shut by default, because the default is right almost every time, and the
   * button carries the settings that are not so nothing is silently on.
   */
  var SLIDERS = {
    pages: {
      fallback: 1,
      describe: function (n) {
        if (n === 1) return 'One page, read deeply — its stylesheets, its scripts and its source maps.';
        if (n <= 5) return n + ' pages, compared against each other. Site-wide signals need a few to fire.';
        return 'Up to ' + n + ' pages. It stops early if the site is slow, and says how many it managed.';
      }
    },
    files: {
      fallback: 3,
      describe: function (n) {
        if (n === 1) return 'One source file, the largest worth reading. A style needs more than one file to be a style.';
        if (n <= 5) return n + ' source files, the largest worth reading. Enough for a style, not for a codebase.';
        return 'Up to ' + n + ' source files. It stops early if the clock runs out, and says how many it managed.';
      }
    }
  };

  function paramsControl(mode, keys) {
    var open = $('params-open-' + mode);
    var panel = $('params-panel-' + mode);
    var badge = $('params-badge-' + mode);
    var parts = [];

    for (var i = 0; i < keys.length; i++) {
      var key = keys[i];
      var range = $(key + '-' + mode);
      if (!range) continue;
      parts.push({
        key: key,
        spec: SLIDERS[key],
        range: range,
        out: $(key + '-out-' + mode),
        note: $(key + '-note-' + mode)
      });
    }
    if (!open || !panel || !parts.length) {
      return { value: function (key) { return SLIDERS[key] ? SLIDERS[key].fallback : 1; } };
    }

    function read(part) {
      return parseInt(part.range.value, 10) || part.spec.fallback;
    }

    function paint() {
      var set = [];
      for (var i = 0; i < parts.length; i++) {
        var part = parts[i];
        var n = read(part);
        if (part.out) part.out.textContent = String(n);
        if (part.note) part.note.textContent = part.spec.describe(n);
        if (n !== part.spec.fallback) set.push(String(n));
      }
      // One number when one slider has been moved, both separated when two
      // have. It is a readout of the settings, so it shows all of them.
      if (badge) {
        badge.textContent = set.join('·');
        badge.hidden = set.length === 0;
      }
      open.classList.toggle('is-set', set.length > 0);
    }

    function setOpen(on) {
      panel.hidden = !on;
      open.setAttribute('aria-expanded', on ? 'true' : 'false');
    }

    open.addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(panel.hidden);
    });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    // Anywhere else, and Escape, closes it. A panel that traps a click is a
    // panel somebody has to hunt for the way out of.
    document.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) { setOpen(false); open.focus(); }
    });
    for (var j = 0; j < parts.length; j++) {
      parts[j].range.addEventListener('input', paint);
    }
    paint();

    return {
      value: function (key) {
        for (var i = 0; i < parts.length; i++) {
          if (parts[i].key === key) return read(parts[i]);
        }
        return SLIDERS[key] ? SLIDERS[key].fallback : 1;
      }
    };
  }

  var autoParams = paramsControl('auto', ['pages', 'files']);
  var urlParams = paramsControl('url', ['pages']);
  var repoParams = paramsControl('repo', ['files']);

  $('form-auto').addEventListener('submit', function (e) {
    e.preventDefault();
    var value = $('input').value;
    if (!value.trim()) { showError('Paste something first.'); return; }
    var body = new FormData();
    body.append('mode', 'auto');
    body.append('input', value);
    // Both are sent whatever the paste turns out to be; the endpoint reads
    // only the one its chosen mode has a use for.
    body.append('pages', String(autoParams.value('pages')));
    body.append('files', String(autoParams.value('files')));
    send(this, $('spin-auto'), body);
  });

  $('form-url').addEventListener('submit', function (e) {
    e.preventDefault();
    var value = $('url').value.trim();
    if (!value) { showError('Put a URL in the box first.'); return; }
    var body = new FormData();
    body.append('mode', 'url');
    body.append('url', value);
    body.append('pages', String(urlParams.value('pages')));
    send(this, $('spin-url'), body);
  });

  $('form-repo').addEventListener('submit', function (e) {
    e.preventDefault();
    var value = $('repo').value.trim();
    if (!value) { showError('Name a repository first — owner/name, or a github.com link.'); return; }
    var body = new FormData();
    body.append('mode', 'repo');
    body.append('repo', value);
    body.append('files', String(repoParams.value('files')));
    send(this, $('spin-repo'), body);
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

  // Two things worth pointing the tool at, one of which is this project. They
  // fill the field and run it, so the claim on the front page is one click from
  // being checked rather than something to take on trust. Revealed only now,
  // because a button that fills a field and submits it is a lie without this
  // script to do either.
  var tries = $('tries');
  if (tries) {
    var byMode = { url: { tab: 'tab-url', input: 'url' }, repo: { tab: 'tab-repo', input: 'repo' } };
    tries.addEventListener('click', function (e) {
      var button = e.target.closest ? e.target.closest('.try') : null;
      if (!button) return;
      var mode = byMode[button.getAttribute('data-mode')];
      if (!mode) return;
      selectTab(tabIndex(mode.tab));
      var field = $(mode.input);
      field.value = button.getAttribute('data-value') || '';
      field.focus();
      var form = field.form;
      if (form) {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
      }
    });
    tries.hidden = false;
  }

  /*
   * What the auto field looks like, as you type.
   *
   * A coarse mirror of Subject::classify() in PHP, which is the authority —
   * this only has to be right often enough to be reassuring, and it says
   * "looks like" rather than naming a decision it does not get to make. The
   * server classifies again on submit and the result reports what it chose,
   * so the two disagreeing costs a moment of surprise and never a wrong read.
   */
  var autoField = $('input');
  var reads = $('reads');

  function looksLike(value) {
    var s = value.trim();
    if (!s) return '';
    if (/^\s*[0-9a-f]{6,40}\|\d{6,}\|/im.test(s)) return 'a git log';
    if (/^commit\s+[0-9a-f]{7,40}\s*$/im.test(s) && /^(Author|Date):\s/im.test(s)) return 'a git log';
    if (s.indexOf('\n') !== -1) return 'source';
    if (/^(?:https?:\/\/)?(?:www\.)?github\.com\/[^/\s]+\/[^/\s?#]+/i.test(s)) return 'a repository';
    if (/^[A-Za-z0-9][A-Za-z0-9-]{0,38}\/[A-Za-z0-9_-]{1,100}$/.test(s)) return 'a repository';
    if (/^https?:\/\/\S+$/i.test(s)) return 'an address';
    if (/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,24}(?::\d{1,5})?(?:\/\S*)?$/i.test(s) && !/[\s<>{}()[\]"'`;=,]/.test(s)) return 'an address';
    return 'source';
  }

  if (autoField && reads) {
    var idle = reads.innerHTML;
    autoField.addEventListener('input', function () {
      // Grow with the paste: one line for an address, several for a log.
      autoField.style.height = 'auto';
      autoField.style.height = Math.min(autoField.scrollHeight, 320) + 'px';

      var guess = looksLike(autoField.value);
      reads.innerHTML = '';
      if (!guess) {
        reads.innerHTML = idle;
        return;
      }
      reads.appendChild(el('span', 'reads-label', 'Looks like'));
      reads.appendChild(el('span', 'reads-value', guess));
    });
  }

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
    if (data.mode === 'repo' && data.stats) {
      var scope = [];
      if (data.stats.commits) scope.push(compact(data.stats.commits) + ' commits');
      if (data.stats.files) scope.push(compact(data.stats.files) + ' files');
      if (scope.length) counts = scope.join(' · ') + ' · ' + counts;
    }
    if (data.counts.human) counts += ' · ' + compact(data.counts.human) + ' pointing the other way';
    $('r-counts').textContent = counts;

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

  // Whole-site mode lists the pages it read; repository mode lists the files.
  // Same block, because they answer the same question — what was actually
  // looked at — and a reader who does not ask it is the reader this whole
  // page is built to argue with.
  function renderPages(data) {
    var host = $('r-pages');
    var title = $('r-pages-title');
    var list = $('r-pages-list');
    var pages = (data.stats && data.stats.perPage) || [];
    var files = (data.stats && data.stats.filesRead) || [];

    list.textContent = '';

    if (data.mode === 'repo' && files.length) {
      title.textContent = 'Files read in full';
      files.forEach(function (f) {
        var li = el('li');
        li.appendChild(el('span', 'page-path', f.path));
        li.appendChild(el('span', 'page-words', compact(f.lines) + ' lines'));
        li.appendChild(el('span', 'page-score is-unknown', f.language || ''));
        list.appendChild(li);
      });
      host.hidden = false;
      return;
    }

    if (pages.length < 2) {
      host.hidden = true;
      return;
    }

    title.textContent = 'Pages read';
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
