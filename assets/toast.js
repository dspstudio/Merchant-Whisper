(function () {
  'use strict';

  var cfg = window.mwSalesToast;
  if (!cfg) return;

  var delivery = cfg.delivery === 'inline' ? 'inline' : 'rest';
  if (delivery === 'rest' && !cfg.endpoint) return;

  var analyticsCfg = cfg.analytics && cfg.analytics.endpoint ? cfg.analytics : null;

  function track(eventName, payload) {
    if (!analyticsCfg || !cfg.nonce) return;
    var body = {
      event: eventName,
      pageType: analyticsCfg.pageType || 'other',
      _mwst_nonce: cfg.nonce || ''
    };
    if (payload) {
      if (payload.productId) body.productId = Number(payload.productId) || 0;
      if (payload.source) body.source = String(payload.source);
      if (payload.type) body.type = String(payload.type);
      if (payload.reason) body.reason = String(payload.reason);
      if (payload.dwellMs != null) body.dwellMs = Number(payload.dwellMs) || 0;
    }
    var url = analyticsCfg.endpoint;
    var headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-MW-ST-Nonce': cfg.nonce
    };
    var json = JSON.stringify(body);
    try {
      if (navigator.sendBeacon) {
        var blob = new Blob([json], { type: 'application/json' });
        // sendBeacon cannot set custom headers — fall through to fetch.
      }
    } catch (e) {
      /* ignore */
    }
    try {
      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: json,
        keepalive: true
      }).catch(function () {});
    } catch (err) {
      /* ignore */
    }
  }

  function setAttrCookie(productId) {
    var id = Number(productId) || 0;
    if (id < 1) return;
    try {
      var maxAge = 1800;
      var secure = location.protocol === 'https:' ? '; Secure' : '';
      document.cookie =
        'mw_st_attr=' +
        id +
        '.' +
        Math.floor(Date.now() / 1000) +
        '; path=/; max-age=' +
        maxAge +
        '; SameSite=Lax' +
        secure;
    } catch (e) {
      /* ignore */
    }
  }

  if (
    cfg.respectReducedMotion !== false &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches
  ) {
    track('skipped', { reason: 'reduced_motion' });
    return;
  }

  if (
    cfg.disableMobile &&
    window.matchMedia(
      '(max-width: ' + Math.max(319, (Number(cfg.mobileBreakpoint) || 768) - 1) + 'px)'
    ).matches
  ) {
    track('skipped', { reason: 'mobile' });
    return;
  }

  var MUTE_KEY = 'mw_st_mute_until';
  var COUNT_KEY = 'mw_st_toast_count';
  var delay = Number(cfg.delay) || 6000;
  var duration = Number(cfg.duration) || 7000;
  var gap = Number(cfg.gap) || 12000;
  var jitter = Math.max(0, Math.min(50, Number(cfg.jitter) || 0));
  var position = cfg.position || 'bottom-left';
  var imageFit = cfg.imageFit === 'padded' ? 'padded' : 'full';
  var maxPerSession = Number(cfg.maxPerSession) || 12;
  var muteHours = Number(cfg.muteHours) || 0;
  var refetchMs = delivery === 'inline' ? 0 : Number(cfg.refetchMs) || 300000;
  var whenStyle = cfg.whenStyle === 'exact' ? 'exact' : 'natural';
  var i18n = cfg.i18n || {};
  var template =
    cfg.messageTemplate || '{name} from {city} just bought {product}';
  var viewingTemplate =
    cfg.viewingTemplate || '{count} {people} are viewing {product}';
  var reviewTemplate =
    cfg.reviewTemplate || '{name} left a {rating}-star review of {product}';
  var ctaOnce = cfg.ctaOnce !== false;
  var CTA_KEY = 'mw_st_cta_shown';
  var VISITOR_KEY = 'mw_st_vid';

  var events = [];
  var index = 0;
  var stopped = false;
  var hideTimer = null;
  var gapTimer = null;
  var el = null;
  var hovering = false;
  var hideArmed = false;
  var hideRemaining = duration;
  var hideStartedAt = 0;
  var currentEvent = null;
  var shownAt = 0;
  var dismissPending = false;
  var shownThisSession = readSessionCount();

  function readSessionCount() {
    try {
      return parseInt(sessionStorage.getItem(COUNT_KEY) || '0', 10) || 0;
    } catch (e) {
      return 0;
    }
  }

  function bumpSessionCount() {
    shownThisSession += 1;
    try {
      sessionStorage.setItem(COUNT_KEY, String(shownThisSession));
    } catch (e) {
      /* ignore */
    }
  }

  function isMuted() {
    if (muteHours <= 0) return false;
    try {
      var until = parseInt(localStorage.getItem(MUTE_KEY) || '0', 10);
      return until > Date.now();
    } catch (e) {
      return false;
    }
  }

  function setMute() {
    if (muteHours <= 0) return;
    try {
      localStorage.setItem(
        MUTE_KEY,
        String(Date.now() + muteHours * 60 * 60 * 1000)
      );
    } catch (e) {
      /* ignore */
    }
    track('muted', eventPayload(currentEvent));
  }

  function eventType(event) {
    return event && event.type ? String(event.type) : 'sale';
  }

  function eventPayload(event) {
    if (!event) return {};
    return {
      productId: event.productId || 0,
      source: event.demo ? 'demo' : 'real',
      type: eventType(event)
    };
  }

  function dwellMs() {
    return shownAt ? Math.max(0, Date.now() - shownAt) : 0;
  }

  function sessionCapReached() {
    return shownThisSession >= maxPerSession;
  }

  /** Apply ±jitter% randomness; keeps a small minimum. */
  function withJitter(ms) {
    var base = Math.max(0, Number(ms) || 0);
    if (!jitter || base <= 0) {
      return base;
    }
    var factor = 1 + ((Math.random() * 2 - 1) * jitter) / 100;
    return Math.max(250, Math.round(base * factor));
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#96;');
  }

  function stockLabelOf(event) {
    return String(event.stockLabel || event.stock_label || '').trim();
  }

  function stockOf(event) {
    if (event.stock == null || event.stock === '') return '';
    return String(event.stock);
  }

  function templateUsesStock() {
    return /\{stock(_label)?\}/.test(String(template || ''));
  }

  function starsHtml(rating) {
    var n = Math.max(0, Math.min(5, Number(rating) || 0));
    var out = '';
    var i;
    for (i = 1; i <= 5; i++) {
      out +=
        '<span class="mw-sales-toast__star' +
        (i <= n ? ' is-on' : '') +
        '" aria-hidden="true">★</span>';
    }
    return '<span class="mw-sales-toast__stars" aria-label="' + n + '">' + out + '</span>';
  }

  function productHtml(event) {
    return event.url
      ? '<a href="' + escapeAttr(event.url) + '">' + escapeHtml(event.title) + '</a>'
      : '<strong>' + escapeHtml(event.title) + '</strong>';
  }

  function applyTemplate(tpl, event) {
    var stock = stockOf(event);
    var stockLabel = stockLabelOf(event);
    var rating = Number(event.rating) || 0;
    var count = Number(event.count) || 0;
    var people =
      event.people ||
      (count === 1 ? i18n.person || 'person' : i18n.people || 'people');
    var coupon = String(event.coupon || '');

    var out = String(tpl || '')
      .replace(/\{name\}/g, escapeHtml(event.name))
      .replace(/\{city\}/g, escapeHtml(event.city))
      .replace(/\{product\}/g, productHtml(event))
      .replace(/\{stock_label\}/g, escapeHtml(stockLabel))
      .replace(/\{stock\}/g, escapeHtml(stock))
      .replace(/\{count\}/g, escapeHtml(count))
      .replace(/\{people\}/g, escapeHtml(people))
      .replace(/\{rating\}/g, escapeHtml(rating))
      .replace(/\{stars\}/g, starsHtml(rating))
      .replace(/\{excerpt\}/g, escapeHtml(event.excerpt || ''))
      .replace(/\{coupon\}/g, escapeHtml(coupon));

    out = out
      .replace(/\s*[—–\-|·]\s*(<\/?strong>)?\s*$/g, '')
      .replace(/\s*[—–\-|·]\s{2,}/g, ' ')
      .replace(/\(\s*\)/g, '')
      .replace(/\s{2,}/g, ' ')
      .replace(/\s+([.,!?])/g, '$1')
      .trim();

    return out;
  }

  function formatLine(event) {
    var type = eventType(event);
    if (type === 'viewing') {
      return applyTemplate(viewingTemplate, event);
    }
    if (type === 'review') {
      return applyTemplate(reviewTemplate, event);
    }
    if (type === 'cta') {
      var msg = String(event.title || '');
      return applyTemplate(msg, event);
    }
    return applyTemplate(template, event);
  }

  function formatMetaLine(event) {
    var type = eventType(event);
    if (type === 'viewing') {
      return i18n.now || 'now';
    }
    if (type === 'cta') {
      return '';
    }
    var when = formatWhenLabel(event);
    if (type === 'review') {
      var usesStars = /\{stars\}/.test(String(reviewTemplate || ''));
      var excerpt = String(event.excerpt || '').trim();
      var bits = [];
      if (!usesStars && Number(event.rating)) {
        bits.push('★'.repeat(Math.max(1, Math.min(5, Number(event.rating) || 0))));
      }
      if (excerpt && !/\{excerpt\}/.test(String(reviewTemplate || ''))) {
        bits.push(excerpt);
      }
      if (when) {
        bits.push(when);
      }
      return bits.join(' · ');
    }
    var stockLabel = stockLabelOf(event);
    if (stockLabel && !templateUsesStock()) {
      return when ? when + ' · ' + stockLabel : stockLabel;
    }
    return when;
  }

  function pluralUnit(n, one, many) {
    return n === 1 ? one : many;
  }

  /** Exact relative label, e.g. "2 minutes ago". */
  function formatExactWhen(diffSec) {
    var n;
    var unit;
    if (diffSec < 60) {
      n = 1;
      unit = pluralUnit(n, i18n.minute || 'minute', i18n.minutes || 'minutes');
    } else if (diffSec < 3600) {
      n = Math.floor(diffSec / 60);
      unit = pluralUnit(n, i18n.minute || 'minute', i18n.minutes || 'minutes');
    } else if (diffSec < 86400) {
      n = Math.floor(diffSec / 3600);
      unit = pluralUnit(n, i18n.hour || 'hour', i18n.hours || 'hours');
    } else if (diffSec < 604800) {
      n = Math.floor(diffSec / 86400);
      unit = pluralUnit(n, i18n.day || 'day', i18n.days || 'days');
    } else if (diffSec < 2592000) {
      n = Math.floor(diffSec / 604800);
      unit = pluralUnit(n, i18n.week || 'week', i18n.weeks || 'weeks');
    } else {
      n = Math.max(1, Math.floor(diffSec / 2592000));
      unit = pluralUnit(n, i18n.month || 'month', i18n.months || 'months');
    }
    return (i18n.ago || '%s ago').replace('%s', n + ' ' + unit);
  }

  /** Soft phrases for Natural mode. */
  function formatNaturalWhen(diffSec) {
    if (diffSec < 120) {
      return i18n.justNow || 'just now';
    }
    if (diffSec < 3600) {
      return i18n.fewMinutes || 'a few minutes ago';
    }
    if (diffSec < 21600) {
      return i18n.coupleHours || 'a couple of hours ago';
    }
    if (diffSec < 86400) {
      return i18n.earlierToday || 'earlier today';
    }
    if (diffSec < 172800) {
      return i18n.yesterday || 'yesterday';
    }
    if (diffSec < 604800) {
      return i18n.fewDays || 'a few days ago';
    }
    return i18n.recently || 'recently';
  }

  /** Polish legacy demo fragments like "22 minutes". */
  function polishWhenText(text) {
    var t = String(text || '').trim();
    if (!t) return '';

    var m = t.match(/^(\d+)\s+(minutes?|hours?|days?|weeks?)$/i);
    if (!m) {
      return t;
    }

    var n = Math.max(1, parseInt(m[1], 10) || 1);
    var unit = m[2].toLowerCase();
    var seconds = n * 60;
    if (unit.indexOf('week') === 0) {
      seconds = n * 604800;
    } else if (unit.indexOf('day') === 0) {
      seconds = n * 86400;
    } else if (unit.indexOf('hour') === 0) {
      seconds = n * 3600;
    }

    if (whenStyle === 'exact') {
      return (i18n.ago || '%s ago').replace('%s', t);
    }
    return formatNaturalWhen(seconds);
  }

  function formatWhenLabel(event) {
    if (event.whenLiteral || event.demo) {
      return polishWhenText(event.when || '');
    }
    var ts = Number(event.whenTs || event.when_ts || 0);
    if (!ts) {
      return polishWhenText(event.when || '');
    }
    var diff = Math.max(0, Math.floor(Date.now() / 1000 - ts));
    return whenStyle === 'exact' ? formatExactWhen(diff) : formatNaturalWhen(diff);
  }

  function clearHideTimer() {
    window.clearTimeout(hideTimer);
    hideTimer = null;
  }

  function clearGapTimer() {
    window.clearTimeout(gapTimer);
    gapTimer = null;
  }

  function stopAll() {
    stopped = true;
    hovering = false;
    hideArmed = false;
    hide();
    clearGapTimer();
    clearHideTimer();
    detachTriggers();
  }

  function pauseHideTimer() {
    if (hideTimer) {
      hideRemaining = Math.max(0, hideRemaining - (Date.now() - hideStartedAt));
      clearHideTimer();
    }
  }

  function scheduleHide(ms) {
    hideRemaining = typeof ms === 'number' ? ms : duration;
    hideArmed = false;
    hideStartedAt = Date.now();
    clearHideTimer();
    hideTimer = window.setTimeout(function () {
      hideTimer = null;
      if (hovering) {
        hideArmed = true;
        return;
      }
      finishAndGap();
    }, hideRemaining);
  }

  function scheduleGap(ms) {
    if (stopped || sessionCapReached() || isMuted() || !events.length) {
      return;
    }
    clearGapTimer();
    gapTimer = window.setTimeout(function () {
      gapTimer = null;
      next();
    }, typeof ms === 'number' ? ms : gap);
  }

  function finishAndGap() {
    if (currentEvent && !dismissPending) {
      var payload = eventPayload(currentEvent);
      payload.dwellMs = dwellMs();
      track('auto_hide', payload);
    }
    dismissPending = false;
    hide();
    scheduleGap(withJitter(gap));
  }

  function resumeHideTimer() {
    if (hideArmed || hideRemaining <= 0) {
      hideArmed = false;
      finishAndGap();
      return;
    }
    scheduleHide(hideRemaining);
  }

  function ensureEl() {
    if (el) return el;

    el = document.createElement('aside');
    el.className = 'mw-sales-toast mw-sales-toast--' + position + ' mw-sales-toast--media-' + imageFit;
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML =
      '<div class="mw-sales-toast__media" hidden></div>' +
      '<div class="mw-sales-toast__body">' +
      '<p class="mw-sales-toast__text"></p>' +
      '<p class="mw-sales-toast__meta"></p>' +
      '<div class="mw-sales-toast__cta" hidden></div>' +
      '</div>' +
      '<button type="button" class="mw-sales-toast__close" aria-label="Dismiss">×</button>';

    document.body.appendChild(el);

    el.addEventListener('mouseenter', function () {
      if (stopped || !el.classList.contains('is-visible')) return;
      hovering = true;
      pauseHideTimer();
    });

    el.addEventListener('mouseleave', function () {
      if (stopped) return;
      hovering = false;
      resumeHideTimer();
    });

    el.querySelector('.mw-sales-toast__close').addEventListener('click', function () {
      if (currentEvent) {
        var payload = eventPayload(currentEvent);
        payload.dwellMs = dwellMs();
        track('dismiss', payload);
      }
      dismissPending = true;
      setMute();
      stopAll();
    });

    el.addEventListener('click', function (ev) {
      var couponBtn =
        ev.target && ev.target.closest
          ? ev.target.closest('.mw-sales-toast__coupon, [data-mwst-copy]')
          : null;
      if (couponBtn) {
        ev.preventDefault();
        copyCoupon(couponBtn);
        if (currentEvent) {
          track('click', eventPayload(currentEvent));
        }
        return;
      }
      var link = ev.target && ev.target.closest ? ev.target.closest('a') : null;
      if (!link || !currentEvent) return;
      var payload = eventPayload(currentEvent);
      track('click', payload);
      if (payload.productId) {
        setAttrCookie(payload.productId);
      }
    });

    return el;
  }

  function ctaAlreadyShown() {
    if (!ctaOnce) return false;
    try {
      return sessionStorage.getItem(CTA_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function markCtaShown() {
    try {
      sessionStorage.setItem(CTA_KEY, '1');
    } catch (e) {
      /* ignore */
    }
  }

  function visitorId() {
    try {
      var id = sessionStorage.getItem(VISITOR_KEY);
      if (id && /^[a-zA-Z0-9]{8,32}$/.test(id)) {
        return id;
      }
      id = '';
      while (id.length < 16) {
        id += Math.random().toString(36).slice(2);
      }
      id = id.replace(/[^a-zA-Z0-9]/g, '').slice(0, 16);
      sessionStorage.setItem(VISITOR_KEY, id);
      return id;
    } catch (e) {
      return '';
    }
  }

  function copyCoupon(btn) {
    var code = btn.getAttribute('data-code') || '';
    if (!code) return;
    var done = function (ok) {
      var prev = btn.getAttribute('data-label') || btn.textContent;
      if (!btn.getAttribute('data-label')) {
        btn.setAttribute('data-label', prev);
      }
      btn.textContent = ok ? i18n.copied || 'Copied' : i18n.copyFailed || 'Copy failed';
      window.setTimeout(function () {
        btn.textContent = btn.getAttribute('data-label') || prev;
      }, 1600);
    };
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(
          function () {
            done(true);
          },
          function () {
            done(false);
          }
        );
        return;
      }
    } catch (e) {
      /* fall through */
    }
    try {
      var ta = document.createElement('textarea');
      ta.value = code;
      ta.setAttribute('readonly', '');
      ta.style.position = 'absolute';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      done(document.execCommand('copy'));
      document.body.removeChild(ta);
    } catch (err) {
      done(false);
    }
  }

  function renderCta(root, event) {
    var box = root.querySelector('.mw-sales-toast__cta');
    if (!box) return;
    if (eventType(event) !== 'cta') {
      box.hidden = true;
      box.innerHTML = '';
      return;
    }
    var coupon = String(event.coupon || '').trim();
    var label = String(event.ctaLabel || i18n.copied || 'Copy code');
    var url = String(event.ctaUrl || event.url || '').trim();
    var html = '';
    if (coupon) {
      html +=
        '<button type="button" class="mw-sales-toast__coupon" data-code="' +
        escapeAttr(coupon) +
        '">' +
        escapeHtml(coupon) +
        '</button>';
    }
    if (url) {
      html +=
        '<a class="mw-sales-toast__btn" href="' +
        escapeAttr(url) +
        '">' +
        escapeHtml(label) +
        '</a>';
    } else if (coupon) {
      html +=
        '<button type="button" class="mw-sales-toast__btn" data-mwst-copy="1" data-code="' +
        escapeAttr(coupon) +
        '">' +
        escapeHtml(label) +
        '</button>';
    }
    box.innerHTML = html;
    box.hidden = !html;
  }

  function show(event) {
    if (stopped || !event || sessionCapReached()) return;

    var root = ensureEl();
    var media = root.querySelector('.mw-sales-toast__media');
    var text = root.querySelector('.mw-sales-toast__text');
    var meta = root.querySelector('.mw-sales-toast__meta');
    var type = eventType(event);

    currentEvent = event;
    shownAt = Date.now();
    dismissPending = false;

    text.innerHTML = formatLine(event);
    var metaLine = formatMetaLine(event);
    meta.textContent = metaLine;
    meta.hidden = !metaLine;
    renderCta(root, event);

    root.classList.toggle('mw-sales-toast--viewing', type === 'viewing');
    root.classList.toggle('mw-sales-toast--review', type === 'review');
    root.classList.toggle('mw-sales-toast--cta', type === 'cta');

    if (event.image && event.url) {
      media.hidden = false;
      media.innerHTML =
        '<a href="' +
        escapeAttr(event.url) +
        '"><img src="' +
        escapeAttr(event.image) +
        '" alt="" loading="lazy" width="48" height="48"></a>';
    } else if (event.image) {
      media.hidden = false;
      media.innerHTML =
        '<img src="' +
        escapeAttr(event.image) +
        '" alt="" loading="lazy" width="48" height="48">';
    } else {
      media.hidden = true;
      media.innerHTML = '';
    }

    root.classList.remove('is-leaving');
    void root.offsetWidth;
    root.classList.add('is-visible');
    bumpSessionCount();
    track('impression', eventPayload(event));
    if (type === 'cta') {
      markCtaShown();
    }

    if (cfg.soundEnabled && typeof window.mwSalesToastPlayPop === 'function') {
      try {
        window.mwSalesToastPlayPop();
      } catch (e) {
        /* ignore autoplay / audio errors */
      }
    }

    if (hovering) {
      hideRemaining = duration;
      hideArmed = false;
      return;
    }

    scheduleHide(duration);
  }

  function hide() {
    if (!el) return;
    el.classList.add('is-leaving');
    el.classList.remove('is-visible');
    clearHideTimer();
    hideArmed = false;
  }

  function next() {
    if (stopped || !events.length) {
      clearGapTimer();
      return;
    }
    if (sessionCapReached()) {
      track('skipped', { reason: 'session_cap' });
      clearGapTimer();
      return;
    }
    if (isMuted()) {
      track('skipped', { reason: 'mute' });
      clearGapTimer();
      return;
    }
    var attempts = 0;
    while (attempts < events.length) {
      var event = events[index % events.length];
      index += 1;
      attempts += 1;
      if (eventType(event) === 'cta' && ctaAlreadyShown()) {
        continue;
      }
      show(event);
      return;
    }
    clearGapTimer();
  }

  function normalizeEvents(data) {
    if (!Array.isArray(data)) return [];
    var matchProduct =
      !!cfg.matchProductPage && Number(cfg.currentProductId) > 0;
    var currentId = Number(cfg.currentProductId) || 0;
    return data.filter(function (item) {
      if (!item || !item.title) return false;
      var type = item.type ? String(item.type) : 'sale';
      if (type === 'cta') return true;
      if (!matchProduct) return true;
      return Number(item.productId) === currentId;
    });
  }

  function fetchEvents() {
    var headers = { Accept: 'application/json' };
    // Custom header — not X-WP-Nonce (that is reserved for wp_rest cookie auth).
    if (cfg.nonce) {
      headers['X-MW-ST-Nonce'] = cfg.nonce;
    }

    var url = cfg.endpoint;
    var pid = Number(cfg.currentProductId) || 0;
    if (pid > 0) {
      url += (url.indexOf('?') === -1 ? '?' : '&') + 'product=' + pid;
    }

    return fetch(url, {
      credentials: 'same-origin',
      headers: headers,
    })
      .then(function (res) {
        if (!res.ok) throw new Error('bad status');
        return res.json();
      })
      .then(normalizeEvents)
      .catch(function () {
        return [];
      });
  }

  function startLoop() {
    if (stopped || !events.length) return;
    if (isMuted()) {
      track('skipped', { reason: 'mute' });
      return;
    }
    if (sessionCapReached()) {
      track('skipped', { reason: 'session_cap' });
      return;
    }
    attachTriggers();
  }

  var loopStarted = false;
  var pageLoadTimer = null;
  var triggerCleanups = [];

  function addCleanup(fn) {
    triggerCleanups.push(fn);
  }

  function detachTriggers() {
    if (pageLoadTimer) {
      window.clearTimeout(pageLoadTimer);
      pageLoadTimer = null;
    }
    while (triggerCleanups.length) {
      try {
        triggerCleanups.pop()();
      } catch (e) {
        /* ignore */
      }
    }
  }

  function beginLoop(reason) {
    if (loopStarted || stopped || !events.length) return;
    if (isMuted()) {
      track('skipped', { reason: 'mute' });
      detachTriggers();
      return;
    }
    if (sessionCapReached()) {
      track('skipped', { reason: 'session_cap' });
      detachTriggers();
      return;
    }
    loopStarted = true;
    detachTriggers();
    if (reason === 'page_load') {
      next();
      return;
    }
    var wait = reason === 'exit_intent' ? 60 : 350;
    window.setTimeout(next, wait);
  }

  function scrollPercent() {
    var el = document.documentElement;
    var top = window.pageYOffset || el.scrollTop || 0;
    var max = (el.scrollHeight || 0) - (el.clientHeight || 0);
    if (max <= 0) return 100;
    return (top / max) * 100;
  }

  function attachTriggers() {
    var t = cfg.triggers || {};
    var any =
      t.pageLoad ||
      t.scroll ||
      t.exitIntent ||
      t.addToCart ||
      t.inactivity ||
      t.click;
    if (!any) {
      t.pageLoad = true;
    }

    if (t.pageLoad) {
      pageLoadTimer = window.setTimeout(function () {
        pageLoadTimer = null;
        beginLoop('page_load');
      }, withJitter(delay));
    }

    if (t.scroll) {
      var pct = Math.max(1, Math.min(100, Number(t.scrollPercent) || 50));
      var onScroll = function () {
        if (scrollPercent() >= pct) {
          beginLoop('scroll');
        }
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      addCleanup(function () {
        window.removeEventListener('scroll', onScroll);
      });
      onScroll();
    }

    if (t.exitIntent) {
      var coarse = false;
      try {
        coarse = window.matchMedia('(pointer: coarse)').matches;
      } catch (e) {
        coarse = 'ontouchstart' in window;
      }
      if (!coarse) {
        var onOut = function (e) {
          if (!e) return;
          if (e.relatedTarget) return;
          if (typeof e.clientY === 'number' && e.clientY > 16) return;
          beginLoop('exit_intent');
        };
        document.addEventListener('mouseout', onOut);
        addCleanup(function () {
          document.removeEventListener('mouseout', onOut);
        });
      }
    }

    if (t.addToCart) {
      var onCart = function () {
        beginLoop('add_to_cart');
      };
      document.body.addEventListener('added_to_cart', onCart);
      document.addEventListener('added_to_cart', onCart);
      document.body.addEventListener('wc-blocks_added_to_cart', onCart);
      document.addEventListener('wc-blocks_added_to_cart', onCart);
      window.addEventListener('wc-blocks_added_to_cart', onCart);
      addCleanup(function () {
        document.body.removeEventListener('added_to_cart', onCart);
        document.removeEventListener('added_to_cart', onCart);
        document.body.removeEventListener('wc-blocks_added_to_cart', onCart);
        document.removeEventListener('wc-blocks_added_to_cart', onCart);
        window.removeEventListener('wc-blocks_added_to_cart', onCart);
      });
      if (window.jQuery && window.jQuery.fn) {
        window.jQuery(document.body).on('added_to_cart.mwst', onCart);
        addCleanup(function () {
          try {
            window.jQuery(document.body).off('added_to_cart.mwst', onCart);
          } catch (e) {
            /* ignore */
          }
        });
      }
      try {
        var params = new URLSearchParams(window.location.search);
        if (params.has('add-to-cart') || params.has('added-to-cart')) {
          beginLoop('add_to_cart');
        }
      } catch (e) {
        /* ignore */
      }
      if (typeof window.fetch === 'function') {
        var origFetch = window.fetch;
        window.fetch = function () {
          var req = arguments[0];
          var url = '';
          try {
            url = typeof req === 'string' ? req : String((req && req.url) || '');
          } catch (err) {
            url = '';
          }
          return origFetch.apply(this, arguments).then(function (res) {
            if (
              res &&
              res.ok &&
              /\/wc\/store(?:\/v\d+)?\/cart\/add-item/i.test(url)
            ) {
              beginLoop('add_to_cart');
            }
            return res;
          });
        };
      }
      if (window.wp && window.wp.apiFetch && typeof window.wp.apiFetch.use === 'function') {
        window.wp.apiFetch.use(function (options, next) {
          return next(options).then(function (result) {
            var path = String((options && (options.path || options.url)) || '');
            if (/\/wc\/store(?:\/v\d+)?\/cart\/add-item/i.test(path)) {
              beginLoop('add_to_cart');
            }
            return result;
          });
        });
      }
    }

    if (t.inactivity) {
      var idleMs = Math.max(5, Number(t.idleSeconds) || 20) * 1000;
      var idleTimer = null;
      var bumpIdle = function () {
        if (idleTimer) window.clearTimeout(idleTimer);
        idleTimer = window.setTimeout(function () {
          idleTimer = null;
          beginLoop('inactivity');
        }, idleMs);
      };
      var idleEvts = ['pointerdown', 'keydown', 'scroll', 'touchstart', 'mousemove'];
      idleEvts.forEach(function (name) {
        window.addEventListener(name, bumpIdle, { passive: true });
      });
      addCleanup(function () {
        if (idleTimer) window.clearTimeout(idleTimer);
        idleEvts.forEach(function (name) {
          window.removeEventListener(name, bumpIdle);
        });
      });
      bumpIdle();
    }

    if (t.click && t.clickSelector) {
      var sel = String(t.clickSelector || '').trim();
      if (sel) {
        var onClick = function (e) {
          try {
            if (e.target && e.target.closest && e.target.closest(sel)) {
              beginLoop('click');
            }
          } catch (err) {
            /* invalid selector */
          }
        };
        document.addEventListener('click', onClick, true);
        addCleanup(function () {
          document.removeEventListener('click', onClick, true);
        });
      }
    }
  }

  function pingPresence() {
    if (!cfg.presenceEndpoint || !cfg.nonce) {
      return Promise.resolve();
    }
    var pid = Number(cfg.currentProductId) || 0;
    var vid = visitorId();
    if (pid < 1 || !vid) {
      return Promise.resolve();
    }
    var send = function () {
      try {
        return fetch(cfg.presenceEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-MW-ST-Nonce': cfg.nonce
          },
          body: JSON.stringify({ productId: pid, visitor: vid }),
          keepalive: true
        }).catch(function () {});
      } catch (e) {
        return Promise.resolve();
      }
    };
    window.setInterval(function () {
      if (stopped || document.visibilityState === 'hidden') return;
      send();
    }, 45000);
    return send();
  }

  function boot(list) {
    events = normalizeEvents(list);
    if (!events.length) return;
    startLoop();
  }

  pingPresence().then(function () {
    if (delivery === 'inline') {
      boot(cfg.events || []);
    } else {
      fetchEvents().then(boot);

      if (refetchMs > 0) {
        window.setInterval(function () {
          if (stopped || isMuted() || sessionCapReached()) return;
          fetchEvents().then(function (list) {
            if (!list.length) return;
            events = list;
          });
        }, refetchMs);
      }
    }
  });
})();
