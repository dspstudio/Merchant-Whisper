(function () {
	'use strict';

	var root = document.getElementById('mwst-admin');
	if (!root) {
		return;
	}

	var cfg = window.mwSalesToastAdmin || {};
	var i18n = cfg.i18n || {};
	var designDefaults = cfg.designDefaults || {};
	var elementorThemeCfg = cfg.elementorTheme || {};
	var tabs = root.querySelectorAll('.mwst-tabs__btn');
	var panels = root.querySelectorAll('.mwst-panel');
	var enabled = root.querySelector('#mwst-enabled');
	var badge = root.querySelector('#mwst-status-badge');
	var template = root.querySelector('#mwst-template');
	var fallback = root.querySelector('#mwst-fallback');
	var hideNames = root.querySelector('#mwst-hide-names');
	var showImage = root.querySelector('#mwst-show-image');
	var sampleList = root.querySelector('#mwst-sample-list');
	var posCaption = root.querySelector('#mwst-pos-caption');
	var dismissNotice = root.querySelector('#mwst-dismiss-notice');
	var savedNotice = root.querySelector('#mwst-saved-notice');
	var resetDesignBtn = root.querySelector('#mwst-reset-design');
	var designInputs = root.querySelectorAll('.mwst-design-input');
	var designPresetInputs = root.querySelectorAll('.mwst-design-preset-input');
	var useElementorTheme = root.querySelector('#mwst-use-elementor-theme');
	var customColorsField = root.querySelector('#mwst-custom-colors-field');
	var imageFitInputs = root.querySelectorAll('.mwst-image-fit-input');
	var imageFitField = root.querySelector('#mwst-image-fit');
	var applyingDesignPreset = false;
	var soundEnabled = root.querySelector('#mwst-sound-enabled');
	var testSoundBtn = root.querySelector('#mwst-test-sound');
	var timingCustom = root.querySelector('#mwst-timing-custom');
	var timingPresets = root.querySelectorAll('.mwst-timing-preset');
	var delayInput = root.querySelector('#mwst-delay');
	var durationInputField = root.querySelector('#mwst-duration');
	var gapInput = root.querySelector('#mwst-gap');
	var jitterInput = root.querySelector('#mwst-jitter');
	var maxEventsInput = root.querySelector('#mwst-max');
	var cycleEstimate = root.querySelector('#mwst-cycle-estimate');
	var triggerInputs = root.querySelectorAll('.mwst-trigger-input');
	var triggerScrollOpt = root.querySelector('#mwst-trigger-scroll-opt');
	var triggerIdleOpt = root.querySelector('#mwst-trigger-idle-opt');
	var triggerClickOpt = root.querySelector('#mwst-trigger-click-opt');
	var typeInputs = root.querySelectorAll('.mwst-type-input');
	var typeSaleOpt = root.querySelector('#mwst-type-sale-opt');
	var typeViewingOpt = root.querySelector('#mwst-type-viewing-opt');
	var typeReviewOpt = root.querySelector('#mwst-type-review-opt');
	var typeCtaOpt = root.querySelector('#mwst-type-cta-opt');
	var viewingMode = root.querySelector('#mwst-viewing-mode');
	var viewingProductsField = root.querySelector('#mwst-viewing-products-field');
	var viewingMinWrap = root.querySelector('#mwst-viewing-min-wrap');
	var viewingWindowWrap = root.querySelector('#mwst-viewing-window-wrap');
	var viewingCountDesc = root.querySelector('#mwst-viewing-count-desc');
	var viewingLiveDesc = root.querySelector('#mwst-viewing-live-desc');
	var whenStyle = root.querySelector('#mwst-when-style');
	var stockDisplay = root.querySelector('#mwst-stock-display');
	var stockThreshold = root.querySelector('#mwst-stock-threshold');
	var stockThresholdField = root.querySelector('#mwst-stock-threshold-field');
	var showOnSelect = root.querySelector('#mwst-show-on');
	var excludeHomeField = root.querySelector('#mwst-exclude-home-field');
	var disableMobile = root.querySelector('#mwst-disable-mobile');
	var mobileBreakpoint = root.querySelector('#mwst-mobile-breakpoint');
	var mobileBreakpointField = root.querySelector('#mwst-mobile-breakpoint-field');

	var liveEl = null;
	var liveVisible = false;
	var hideTimer = null;
	var previewType = 'sale';
	var designStyleEl = null;
	var cssCodeMirror = null;

	var PLACEHOLDER_IMG =
		'data:image/svg+xml,' +
		encodeURIComponent(
			'<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">' +
				'<rect width="96" height="96" rx="16" fill="#2a3548"/>' +
				'<rect x="18" y="22" width="60" height="40" rx="6" fill="#3d4d66"/>' +
				'<circle cx="36" cy="38" r="7" fill="#6b7c96"/>' +
				'<path d="M22 56l16-12 12 9 10-7 14 14H22z" fill="#5a6b84"/>' +
				'</svg>'
		);

	var posLabels = {
		'bottom-left': 'Bottom left',
		'bottom-right': 'Bottom right',
		'top-left': 'Top left',
		'top-right': 'Top right'
	};

	var saveBar = root.querySelector('#mwst-save-bar');
	var saveHint = root.querySelector('#mwst-save-hint');
	var saveSpinner = root.querySelector('#mwst-save-spinner');
	var settingsForm =
		root.querySelector('#mwst-settings-form') ||
		root.querySelector('form[action="options.php"]');
	var supportStatus = root.querySelector('#mwst-support-status');
	var supportSubmit = root.querySelector('#mwst-support-submit');
	var supportCfg = cfg.support || {};
	var cacheCfg = cfg.cache || {};
	var saveHintDefault = saveHint ? saveHint.textContent : '';
	var optionPrefix = cfg.optionName || 'mw_sales_toast_settings';
	var saveDirty = false;
	var saveSnapshot = '';
	var saveState = {};
	var saveLeaving = false;
	var dirtyReady = false;
	var userTouchedForm = false;
	var restoringForm = false;
	var saveRevert = root.querySelector('#mwst-save-revert');

	function getSaveSubmitButton() {
		return (
			(saveBar && saveBar.querySelector('input[type="submit"], button[type="submit"]')) ||
			(settingsForm && settingsForm.querySelector('input[type="submit"][name="submit"]'))
		);
	}

	function collectSettingsMap() {
		var map = {};
		if (!settingsForm) {
			return map;
		}
		function add(name, value) {
			if (!Object.prototype.hasOwnProperty.call(map, name)) {
				map[name] = [];
			}
			map[name].push(String(value));
		}
		var fields = settingsForm.querySelectorAll('[name]');
		for (var i = 0; i < fields.length; i++) {
			var el = fields[i];
			var name = el.getAttribute('name') || '';
			if (name.indexOf(optionPrefix) !== 0) {
				continue;
			}
			var tag = (el.tagName || '').toLowerCase();
			if (tag === 'input') {
				var type = (el.type || '').toLowerCase();
				if (type === 'file' || type === 'button' || type === 'submit' || type === 'reset') {
					continue;
				}
				if (type === 'checkbox' || type === 'radio') {
					if (el.checked) {
						add(name, el.value);
					}
				} else {
					add(name, el.value);
				}
			} else if (tag === 'select') {
				if (el.multiple) {
					Array.prototype.forEach.call(el.selectedOptions || [], function (opt) {
						add(name, opt.value);
					});
				} else {
					add(name, el.value);
				}
			} else if (tag === 'textarea') {
				add(name, el.value);
			}
		}
		return map;
	}

	function snapshotFromMap(map) {
		var keys = Object.keys(map).sort();
		var ordered = {};
		for (var i = 0; i < keys.length; i++) {
			ordered[keys[i]] = map[keys[i]];
		}
		return JSON.stringify(ordered);
	}

	function collectSettingsSnapshot() {
		return snapshotFromMap(collectSettingsMap());
	}

	function applySettingsMap(map) {
		if (!settingsForm || !map) {
			return;
		}
		var fields = settingsForm.querySelectorAll('[name]');
		for (var i = 0; i < fields.length; i++) {
			var el = fields[i];
			var name = el.getAttribute('name') || '';
			if (name.indexOf(optionPrefix) !== 0) {
				continue;
			}
			var values = Object.prototype.hasOwnProperty.call(map, name) ? map[name] : [];
			var tag = (el.tagName || '').toLowerCase();
			if (tag === 'input') {
				var type = (el.type || '').toLowerCase();
				if (type === 'file' || type === 'button' || type === 'submit' || type === 'reset') {
					continue;
				}
				if (type === 'checkbox' || type === 'radio') {
					el.checked = values.indexOf(el.value) !== -1;
				} else {
					el.value = values.length ? values[0] : '';
					if (window.jQuery && el.classList.contains('mwst-color-picker')) {
						try {
							window.jQuery(el).wpColorPicker('color', el.value);
						} catch (err) {
							/* picker not ready */
						}
					}
				}
			} else if (tag === 'select') {
				if (window.jQuery && window.jQuery(el).data('select2')) {
					window.jQuery(el).val(el.multiple ? values : values[0] || null).trigger('change');
				} else if (el.multiple) {
					Array.prototype.forEach.call(el.options || [], function (opt) {
						opt.selected = values.indexOf(opt.value) !== -1;
					});
				} else {
					el.value = values.length ? values[0] : '';
				}
			} else if (tag === 'textarea') {
				el.value = values.length ? values[0] : '';
				if (el.id === 'mwst-custom-css' && cssCodeMirror) {
					cssCodeMirror.setValue(el.value);
				}
			}
		}
	}

	function dirtyHintText() {
		return i18n.saveHintDirty || 'Unsaved changes';
	}

	function applySaveHint() {
		if (!saveHint) {
			return;
		}
		if (saveBar && saveBar.classList.contains('is-saving')) {
			saveHint.textContent = i18n.saveHintBusy || 'Saving your settings and rebuilding the sales cache…';
			return;
		}
		saveHint.textContent = saveDirty ? dirtyHintText() : saveHintDefault;
	}

	function setDirty(on) {
		saveDirty = !!on;
		if (saveBar) {
			saveBar.classList.toggle('is-dirty', saveDirty);
		}
		if (saveRevert) {
			saveRevert.hidden = !saveDirty;
		}
		applySaveHint();
	}

	function refreshDirtyFromForm() {
		if (restoringForm || !dirtyReady || !settingsForm) {
			return;
		}
		if (cssCodeMirror) {
			cssCodeMirror.save();
		}
		setDirty(collectSettingsSnapshot() !== saveSnapshot);
	}

	function captureSaveSnapshot(forceClean) {
		if (cssCodeMirror) {
			cssCodeMirror.save();
		}
		saveState = collectSettingsMap();
		saveSnapshot = snapshotFromMap(saveState);
		dirtyReady = true;
		if (forceClean) {
			setDirty(false);
		} else {
			refreshDirtyFromForm();
		}
	}

	function restorePendingChanges() {
		if (restoringForm) {
			return;
		}
		restoringForm = true;
		try {
			applySettingsMap(saveState || {});
			syncBadge();
			syncTimingPreset();
			updateCycleEstimate();
			syncTriggerOptions();
			syncTypeOptions();
			syncViewingModeOptions();
			syncPositionCaption();
			syncLivePosition();
			syncImageFitState();
			syncImageFit();
			syncElementorThemeUi();
			syncDesignPresetUi();
			syncStockThresholdState();
			syncExcludeHomeState();
			syncMobileBreakpointState();
			syncDesign();
			syncSample();
			refreshProductSelects();
		} catch (err) {
			/* keep restoring flag until snapshot reset */
		}
		window.setTimeout(function () {
			userTouchedForm = false;
			captureSaveSnapshot(true);
			restoringForm = false;
		}, 80);
	}

	function setSavingState(on) {
		if (!saveBar || saveBar.classList.contains('is-nonsave-tab')) {
			return;
		}
		saveBar.classList.toggle('is-saving', !!on);
		saveBar.setAttribute('aria-busy', on ? 'true' : 'false');
		if (saveSpinner) {
			saveSpinner.hidden = !on;
		}
		applySaveHint();
		var btn = getSaveSubmitButton();
		if (!btn) {
			return;
		}
		if (on) {
			if (!btn.dataset.mwstLabel) {
				btn.dataset.mwstLabel = btn.value || btn.textContent || '';
			}
			if (btn.tagName === 'INPUT') {
				btn.value = i18n.saving || 'Saving…';
			} else {
				btn.textContent = i18n.saving || 'Saving…';
			}
			// Defer disable so this submit still includes the button name in POST.
			window.setTimeout(function () {
				btn.disabled = true;
			}, 0);
		} else {
			btn.disabled = false;
			var label = btn.dataset.mwstLabel || 'Save settings';
			if (btn.tagName === 'INPUT') {
				btn.value = label;
			} else {
				btn.textContent = label;
			}
		}
	}

	/** Enter in inputs/selects submits Save settings (not in textareas or Support/Account). */
	if (settingsForm) {
		settingsForm.addEventListener('submit', function () {
			saveLeaving = true;
			setSavingState(true);
		});

		settingsForm.addEventListener(
			'input',
			function () {
				if (restoringForm) {
					return;
				}
				userTouchedForm = true;
				refreshDirtyFromForm();
			},
			true
		);
		settingsForm.addEventListener(
			'change',
			function () {
				if (restoringForm) {
					return;
				}
				userTouchedForm = true;
				refreshDirtyFromForm();
			},
			true
		);

		settingsForm.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter') {
				return;
			}
			if (event.defaultPrevented || event.isComposing) {
				return;
			}
			if (event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) {
				return;
			}
			if (saveBar && saveBar.classList.contains('is-nonsave-tab')) {
				return;
			}
			if (saveBar && saveBar.classList.contains('is-saving')) {
				event.preventDefault();
				return;
			}

			var target = event.target;
			if (!target || !settingsForm.contains(target)) {
				return;
			}

			var tag = (target.tagName || '').toLowerCase();
			if (tag === 'textarea') {
				return;
			}
			if (tag === 'button') {
				return;
			}
			if (tag === 'input') {
				var type = (target.type || '').toLowerCase();
				if (
					type === 'submit' ||
					type === 'button' ||
					type === 'reset' ||
					type === 'checkbox' ||
					type === 'radio' ||
					type === 'file'
				) {
					return;
				}
			}

			event.preventDefault();
			var submitBtn = getSaveSubmitButton();
			if (submitBtn && !submitBtn.disabled) {
				submitBtn.click();
			} else if (typeof settingsForm.requestSubmit === 'function') {
				settingsForm.requestSubmit();
			} else {
				settingsForm.submit();
			}
		});
	}

	if (saveBar) {
		saveBar.addEventListener('click', function (event) {
			var target = event.target;
			if (!target || !target.closest) {
				return;
			}
			if (!target.closest('#mwst-save-revert')) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			restorePendingChanges();
		});
	}

	window.addEventListener('beforeunload', function (event) {
		if (!saveDirty || saveLeaving) {
			return;
		}
		event.preventDefault();
		event.returnValue = '';
	});

	// Back/forward cache restores the pre-submit DOM (spinner + "Saving…" still visible).
	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			saveLeaving = false;
			setSavingState(false);
			refreshDirtyFromForm();
		}
	});

	function tabFromUrl() {
		try {
			var id = new URL(window.location.href).searchParams.get('tab') || 'general';
			// Legacy Contact tab → Support (Contact section).
			if (id === 'contact') {
				return 'support';
			}
			// Legacy License tab → Account.
			if (id === 'license') {
				return 'account';
			}
			if (id === 'demo') {
				return 'message';
			}
			return id;
		} catch (err) {
			return 'general';
		}
	}

	function scrollToHashTarget() {
		var hash = window.location.hash;
		if (!hash || hash.length < 2) {
			return;
		}
		var el = root.querySelector(hash);
		if (!el) {
			return;
		}
		if (el.tagName === 'DETAILS') {
			el.open = true;
		}
		window.setTimeout(function () {
			el.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}, 60);
	}

	function isValidTab(id) {
		if (!id) {
			return false;
		}
		for (var i = 0; i < tabs.length; i++) {
			if (tabs[i].getAttribute('data-tab') === id) {
				return true;
			}
		}
		return false;
	}

	function syncTabUrl(id) {
		try {
			var url = new URL(window.location.href);
			url.searchParams.set('tab', id);
			window.history.replaceState({}, '', url.pathname + url.search + url.hash);
		} catch (err) {
			// Ignore URL sync failures (older browsers).
		}

		var referers = root.querySelectorAll('input[name="_wp_http_referer"]');
		referers.forEach(function (referer) {
			if (!referer || !referer.value) {
				return;
			}
			try {
				var ref = new URL(referer.value, window.location.origin);
				ref.searchParams.set('tab', id);
				referer.value = ref.pathname + ref.search + ref.hash;
			} catch (err2) {
				// Ignore referer sync failures.
			}
		});
	}

	function activateTab(id) {
		if (!isValidTab(id)) {
			id = 'general';
		}
		var targetBtn = root.querySelector('.mwst-tabs__btn[data-tab="' + id + '"]');
		if (targetBtn && targetBtn.hidden) {
			id = 'general';
		}

		tabs.forEach(function (btn) {
			var on = btn.getAttribute('data-tab') === id;
			btn.classList.toggle('is-active', on);
			btn.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		panels.forEach(function (panel) {
			panel.classList.toggle('is-active', panel.id === 'mwst-panel-' + id);
		});
		if (saveBar) {
			saveBar.classList.toggle(
				'is-nonsave-tab',
				id === 'statistics' || id === 'support'
			);
		}
		syncTabUrl(id);
		if (id === 'design' && cssCodeMirror) {
			window.setTimeout(function () {
				cssCodeMirror.refresh();
			}, 0);
		}
		if (id === 'message' || id === 'general') {
			refreshProductSelects();
		}
	}

	tabs.forEach(function (btn) {
		btn.addEventListener('click', function () {
			activateTab(btn.getAttribute('data-tab'));
		});
	});

	root.querySelectorAll(
		'.mwst-jump__link, .mwst-tab-link[data-tab], a[href^="#mwst-support-"], a[href^="#mwst-account-"], a[href^="#mwst-stats-"]'
	).forEach(function (link) {
		link.addEventListener('click', function (event) {
			var href = link.getAttribute('href') || '';
			var dataTab = link.getAttribute('data-tab') || '';
			var jumpTab = '';

			if (dataTab && isValidTab(dataTab)) {
				jumpTab = dataTab;
			} else if (href.indexOf('#mwst-account-') === 0) {
				jumpTab = 'account';
			} else if (href.indexOf('#mwst-support-') === 0) {
				jumpTab = 'support';
			} else if (href.indexOf('#mwst-stats-') === 0) {
				jumpTab = 'statistics';
			} else if (href.indexOf('#mwst-panel-') === 0) {
				jumpTab = href.replace('#mwst-panel-', '');
			}

			if (!jumpTab || !isValidTab(jumpTab)) {
				return;
			}

			event.preventDefault();
			activateTab(jumpTab);

			var isInPageAnchor =
				href.charAt(0) === '#' &&
				(href.indexOf('#mwst-support-') === 0 ||
					href.indexOf('#mwst-account-') === 0 ||
					href.indexOf('#mwst-stats-') === 0);

			if (isInPageAnchor) {
				try {
					var url = new URL(window.location.href);
					url.hash = href;
					window.history.replaceState({}, '', url.pathname + url.search + url.hash);
				} catch (err) {
					window.location.hash = href;
				}
				window.setTimeout(scrollToHashTarget, 40);
			} else {
				try {
					var clean = new URL(window.location.href);
					clean.hash = '';
					window.history.replaceState({}, '', clean.pathname + clean.search);
				} catch (err2) {
					/* ignore */
				}
				try {
					root.scrollIntoView({ behavior: 'smooth', block: 'start' });
				} catch (err3) {
					/* ignore */
				}
			}
		});
	});

	function syncBadge() {
		if (!badge || !enabled) {
			return;
		}
		var on = enabled.checked;
		badge.textContent = on ? 'Enabled' : 'Disabled';
		badge.classList.toggle('mwst-badge--on', on);
		badge.classList.toggle('mwst-badge--off', !on);
	}

	function sampleName() {
		if (hideNames && hideNames.checked) {
			return (fallback && fallback.value.trim()) || 'Someone';
		}
		return 'Ana';
	}

	function currentPosition() {
		var checked = root.querySelector('input[name$="[position]"]:checked');
		return checked ? checked.value : 'bottom-left';
	}

	function currentDurationMs() {
		var seconds = durationInputField
			? parseInt(durationInputField.value, 10)
			: 7;
		if (!seconds || seconds < 2) {
			seconds = 7;
		}
		return seconds * 1000;
	}

	function syncTimingPreset() {
		var selected = root.querySelector('.mwst-timing-preset:checked');
		var isCustom = selected && selected.value === 'custom';

		root.querySelectorAll('.mwst-timing-preset').forEach(function (input) {
			var label = input.closest('.mwst-preset');
			if (label) {
				label.classList.toggle('is-active', !!input.checked);
			}
		});

		if (timingCustom) {
			timingCustom.hidden = !isCustom;
		}

		if (selected && !isCustom) {
			if (delayInput) {
				delayInput.value = selected.getAttribute('data-delay') || delayInput.value;
			}
			if (durationInputField) {
				durationInputField.value =
					selected.getAttribute('data-duration') || durationInputField.value;
			}
			if (gapInput) {
				gapInput.value = selected.getAttribute('data-gap') || gapInput.value;
			}
		}

		updateCycleEstimate();
	}

	function fillI18n(template, parts) {
		var i = 0;
		return String(template || '')
			.replace(/%(\d+)\$[sd]/g, function (_, n) {
				return String(parts[Number(n) - 1] == null ? '' : parts[Number(n) - 1]);
			})
			.replace(/%[sd]/g, function () {
				var value = parts[i];
				i += 1;
				return String(value == null ? '' : value);
			})
			.replace(/%%/g, '%');
	}

	function clampInt(value, min, max, fallback) {
		var n = parseInt(value, 10);
		if (!n && n !== 0) {
			n = fallback;
		}
		if (n < min) {
			n = min;
		}
		if (n > max) {
			n = max;
		}
		return n;
	}

	function formatShortDuration(seconds) {
		seconds = Math.max(0, Math.round(Number(seconds) || 0));
		var h = Math.floor(seconds / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;
		if (h > 0) {
			return m > 0
				? fillI18n(i18n.durationHM || '%1$dh %2$dm', [h, m])
				: fillI18n(i18n.durationH || '%dh', [h]);
		}
		if (m > 0) {
			return s > 0
				? fillI18n(i18n.durationMS || '%1$dm %2$ds', [m, s])
				: fillI18n(i18n.durationM || '%dm', [m]);
		}
		return fillI18n(i18n.durationS || '%ds', [s]);
	}

	function updateCycleEstimate() {
		if (!cycleEstimate) {
			return;
		}
		var n = clampInt(maxEventsInput && maxEventsInput.value, 1, 30, 8);
		var delay = clampInt(delayInput && delayInput.value, 1, 120, 6);
		var duration = clampInt(durationInputField && durationInputField.value, 2, 60, 7);
		var gap = clampInt(gapInput && gapInput.value, 1, 300, 12);
		var jitter = clampInt(jitterInput && jitterInput.value, 0, 50, 0);
		var j = jitter / 100;
		var gaps = n - 1;
		var nominal = delay + n * duration + gaps * gap;
		var min = delay * (1 - j) + n * duration + gaps * gap * (1 - j);
		var max = delay * (1 + j) + n * duration + gaps * gap * (1 + j);
		if (jitter > 0) {
			cycleEstimate.textContent = fillI18n(
				i18n.cycleJitter || 'Estimated messages duration %1$s–%2$s (first delay + visible + gaps).',
				[formatShortDuration(min), formatShortDuration(max)]
			);
			return;
		}
		cycleEstimate.textContent = fillI18n(
			i18n.cycleNominal || 'Estimated messages duration %s (first delay + visible + gaps).',
			[formatShortDuration(nominal)]
		);
	}

	function syncTriggerOptions() {
		var selected = {};
		var checkedCount = 0;
		triggerInputs.forEach(function (input) {
			var key = input.getAttribute('data-trigger') || '';
			if (key) {
				selected[key] = !!input.checked;
			}
			if (input.checked) {
				checkedCount += 1;
			}
			var label = input.closest('.mwst-preset');
			if (label) {
				label.classList.toggle('is-active', !!input.checked);
			}
		});
		if (checkedCount < 1) {
			var pageLoad = root.querySelector('.mwst-trigger-input[data-trigger="page_load"]');
			if (pageLoad) {
				pageLoad.checked = true;
				selected.page_load = true;
				var pageLabel = pageLoad.closest('.mwst-preset');
				if (pageLabel) {
					pageLabel.classList.add('is-active');
				}
			}
		}
		if (triggerScrollOpt) {
			triggerScrollOpt.hidden = !selected.scroll;
		}
		if (triggerIdleOpt) {
			triggerIdleOpt.hidden = !selected.inactivity;
		}
		if (triggerClickOpt) {
			triggerClickOpt.hidden = !selected.click;
		}
	}

	function syncTypeOptions() {
		var selected = {};
		var checkedCount = 0;
		typeInputs.forEach(function (input) {
			var key = input.getAttribute('data-type') || '';
			if (key) {
				selected[key] = !!input.checked;
			}
			if (input.checked) {
				checkedCount += 1;
			}
			var label = input.closest('.mwst-preset');
			if (label) {
				label.classList.toggle('is-active', !!input.checked);
			}
		});
		if (checkedCount < 1) {
			var sale = root.querySelector('.mwst-type-input[data-type="sale"]');
			if (sale) {
				sale.checked = true;
				selected.sale = true;
				var saleLabel = sale.closest('.mwst-preset');
				if (saleLabel) {
					saleLabel.classList.add('is-active');
				}
			}
		}
		if (typeSaleOpt) {
			typeSaleOpt.hidden = !selected.sale;
		}
		if (typeViewingOpt) {
			typeViewingOpt.hidden = !selected.viewing;
			if (selected.viewing) {
				syncViewingModeOptions();
			}
		}
		if (typeReviewOpt) {
			typeReviewOpt.hidden = !selected.review;
		}
		if (typeCtaOpt) {
			typeCtaOpt.hidden = !selected.cta;
		}
		if (liveVisible && selectedPreviewTypes().indexOf(previewType) === -1) {
			hideLiveToast();
		}
		syncSample();
	}

	function refreshProductSelects() {
		if (typeof window.jQuery === 'undefined') {
			return;
		}
		window.setTimeout(function () {
			window.jQuery(root)
				.find('.wc-product-search, .wc-enhanced-select')
				.each(function () {
					var $el = window.jQuery(this);
					var $container = $el.next('.select2-container');
					if (!$el.data('select2') || !$container.length) {
						return;
					}
					$container.css({ width: '100%', maxWidth: '100%' });
					$container.find('.select2-search__field').css({
						width: '100%',
						maxWidth: '100%'
					});
					if ($el.data('mwstSelectBound')) {
						return;
					}
					$el.data('mwstSelectBound', true);
					$el.on('select2:open', function () {
						window.setTimeout(function () {
							var w = $container.outerWidth();
							if (!w) {
								return;
							}
							window
								.jQuery('body > .select2-container--open')
								.css({ width: w, maxWidth: w })
								.find('.select2-dropdown')
								.css({ width: w, maxWidth: w });
						}, 0);
					});
				});
		}, 0);
	}

	function refreshViewingProductSelect() {
		refreshProductSelects();
	}

	function syncViewingModeOptions() {
		var live = viewingMode && viewingMode.value === 'live';
		if (viewingProductsField) {
			viewingProductsField.hidden = !!live;
			if (!live) {
				refreshViewingProductSelect();
			}
		}
		if (viewingMinWrap) {
			viewingMinWrap.hidden = !!live;
		}
		if (viewingWindowWrap) {
			viewingWindowWrap.hidden = !live;
		}
		if (viewingCountDesc) {
			viewingCountDesc.hidden = !!live;
		}
		if (viewingLiveDesc) {
			viewingLiveDesc.hidden = !live;
		}
	}

	function sampleStockFields() {
		var mode = stockDisplay ? stockDisplay.value : 'off';
		if (mode === 'off') {
			return { stock: '', stockLabel: '' };
		}
		if (mode === 'exact_low') {
			return {
				stock: '3',
				stockLabel: i18n.sampleStockExact || 'only 3 left'
			};
		}
		return {
			stock: '3',
			stockLabel: i18n.sampleStockSoft || 'only a few left'
		};
	}

	function selectedPreviewTypes() {
		var types = [];
		typeInputs.forEach(function (input) {
			if (input.checked) {
				types.push(input.getAttribute('data-type') || 'sale');
			}
		});
		return types.length ? types : ['sale'];
	}

	function previewTypeTitle(type) {
		var input = root.querySelector('.mwst-type-input[data-type="' + type + '"]');
		var card = input ? input.closest('.mwst-preset') : null;
		var label = card ? card.querySelector('.mwst-preset__label') : null;
		return label ? String(label.textContent || '').trim() : type;
	}

	function fieldValue(selector, fallback) {
		var el = root.querySelector(selector);
		if (!el) {
			return fallback;
		}
		return String(el.value || '').trim() || fallback;
	}

	function starsHtml(rating) {
		var n = Math.max(0, Math.min(5, Number(rating) || 0));
		var out = '<span class="mw-sales-toast__stars">';
		var i;
		for (i = 1; i <= 5; i++) {
			out +=
				'<span class="mw-sales-toast__star' +
				(i <= n ? ' is-on' : '') +
				'">★</span>';
		}
		return out + '</span>';
	}

	function applySampleTemplate(tpl, map) {
		var html = '';
		var tokenRe =
			/(\{name\}|\{city\}|\{product\}|\{stock_label\}|\{stock\}|\{count\}|\{people\}|\{rating\}|\{stars\}|\{excerpt\}|\{coupon\})/g;
		var htmlKeys = {
			'{product}': 1,
			'{stars}': 1
		};

		String(tpl || '')
			.split(tokenRe)
			.forEach(function (part) {
				if (!part) {
					return;
				}
				if (htmlKeys[part]) {
					html += map[part] || '';
					return;
				}
				if (Object.prototype.hasOwnProperty.call(map, part)) {
					html += escapeHtml(map[part] || '');
					return;
				}
				html += escapeHtml(part);
			});

		return html
			.replace(/\s*[—–\-|·]\s*$/g, '')
			.replace(/\s{2,}/g, ' ')
			.trim();
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function agoLabel() {
		if (whenStyle && whenStyle.value === 'exact') {
			var when = i18n.sampleWhen || '2 minutes';
			return (i18n.ago || '%s ago').replace('%s', when);
		}
		return i18n.sampleNatural || 'just now';
	}

	function sampleViewingCount() {
		var min = parseInt(fieldValue('#mwst-viewing-min', '2'), 10);
		var max = parseInt(fieldValue('#mwst-viewing-max', '12'), 10);
		if (isNaN(min) || min < 1) {
			min = 2;
		}
		if (isNaN(max) || max < min) {
			max = Math.max(min, 12);
		}
		min = Math.min(99, min);
		max = Math.min(99, max);
		return Math.round((min + max) / 2);
	}

	function sampleMaps() {
		var stock = sampleStockFields();
		var name = sampleName();
		var product = '<strong>Classic Tee</strong>';
		var coupon = fieldValue('#mwst-cta-coupon', '');
		var rating = 5;
		return {
			sale: {
				tpl:
					(template && template.value) ||
					'{name} from {city} just bought {product}',
				map: {
					'{name}': name,
					'{city}': 'Bucharest',
					'{product}': product,
					'{stock}': stock.stock,
					'{stock_label}': stock.stockLabel
				},
				meta: sampleSaleMeta(stock),
				cta: '',
				showMedia: true
			},
			viewing: {
				tpl:
					fieldValue(
						'#mwst-viewing-template',
						'{count} {people} are viewing {product}'
					),
				map: {
					'{count}': String(sampleViewingCount()),
					'{people}':
						sampleViewingCount() === 1
							? i18n.person || 'person'
							: i18n.people || 'people',
					'{product}': product
				},
				meta: i18n.now || 'now',
				cta: '',
				showMedia: true
			},
			review: {
				tpl:
					fieldValue(
						'#mwst-review-template',
						'{name} left a {rating}-star review of {product}'
					),
				map: {
					'{name}': name,
					'{rating}': String(rating),
					'{stars}': starsHtml(rating),
					'{product}': product,
					'{excerpt}': i18n.sampleExcerpt || 'Exactly what I needed.'
				},
				meta: sampleReviewMeta(rating),
				cta: '',
				showMedia: true
			},
			cta: {
				tpl: fieldValue('#mwst-cta-message', 'Get 10% off your next order'),
				map: {
					'{coupon}': coupon,
					'{product}': product
				},
				meta: '',
				cta: sampleCtaMarkup(coupon),
				showMedia: false
			}
		};
	}

	function sampleSaleMeta(stock) {
		var when = agoLabel();
		var tpl = (template && template.value) || '';
		var usesStock = /\{stock(_label)?\}/.test(tpl);
		if (stock.stockLabel && !usesStock) {
			return when + ' · ' + stock.stockLabel;
		}
		return when;
	}

	function sampleReviewMeta(rating) {
		var tpl = fieldValue('#mwst-review-template', '');
		var bits = [];
		if (!/\{stars\}/.test(tpl)) {
			bits.push('★'.repeat(Math.max(1, Math.min(5, rating))));
		}
		if (
			root.querySelector('#mwst-review-excerpt') &&
			root.querySelector('#mwst-review-excerpt').checked &&
			!/\{excerpt\}/.test(tpl)
		) {
			bits.push(i18n.sampleExcerpt || 'Exactly what I needed.');
		}
		bits.push(agoLabel());
		return bits.join(' · ');
	}

	function sampleCtaMarkup(coupon) {
		var label = fieldValue('#mwst-cta-button', i18n.copyCode || 'Copy code');
		var url = fieldValue('#mwst-cta-url', '');
		var html = '';
		if (coupon) {
			html +=
				'<span class="mw-sales-toast__coupon">' + escapeHtml(coupon) + '</span>';
		}
		if (label) {
			html +=
				'<span class="mw-sales-toast__btn">' + escapeHtml(label) + '</span>';
		}
		return html;
	}

	function fillToastParts(mediaEl, textEl, metaEl, ctaEl, toastEl, type) {
		var samples = sampleMaps();
		var sample = samples[type] || samples.sale;
		var html = applySampleTemplate(sample.tpl, sample.map);

		if (textEl) {
			textEl.innerHTML = html;
		}
		if (metaEl) {
			metaEl.textContent = sample.meta || '';
			metaEl.hidden = !sample.meta;
		}
		if (ctaEl) {
			ctaEl.innerHTML = sample.cta || '';
			ctaEl.hidden = !sample.cta;
		}
		if (toastEl) {
			toastEl.classList.toggle('mw-sales-toast--viewing', type === 'viewing');
			toastEl.classList.toggle('mw-sales-toast--review', type === 'review');
			toastEl.classList.toggle('mw-sales-toast--cta', type === 'cta');
		}
		if (!mediaEl) {
			return;
		}
		var show = sample.showMedia !== false && showImage && showImage.checked;
		if (show) {
			mediaEl.hidden = false;
			mediaEl.innerHTML =
				'<img src="' +
				PLACEHOLDER_IMG +
				'" alt="" width="48" height="48">';
		} else {
			mediaEl.hidden = true;
			mediaEl.innerHTML = '';
		}
	}

	function fillToastEl(toastEl, type) {
		if (!toastEl) {
			return;
		}
		fillToastParts(
			toastEl.querySelector('.mw-sales-toast__media'),
			toastEl.querySelector('.mw-sales-toast__text'),
			toastEl.querySelector('.mw-sales-toast__meta'),
			toastEl.querySelector('.mw-sales-toast__cta'),
			toastEl,
			type
		);
		applyMediaFitClass(toastEl);
	}

	function previewAriaLabel(type) {
		var title = previewTypeTitle(type);
		var tpl = i18n.previewToast || 'Preview %s toast';
		return tpl.replace('%s', title);
	}

	function rebuildSampleList(types) {
		if (!sampleList) {
			return;
		}
		sampleList.innerHTML = '';
		types.forEach(function (type) {
			var item = document.createElement('div');
			item.className = 'mwst-sample-item';
			item.setAttribute('data-preview-type', type);

			var caption = document.createElement('p');
			caption.className = 'mwst-preview-type';
			caption.textContent = previewTypeTitle(type);
			caption.hidden = types.length < 2;

			var toast = document.createElement('aside');
			toast.className =
				'mw-sales-toast mwst-sample-toast is-visible mw-sales-toast--media-' +
				currentImageFit();
			toast.setAttribute('role', 'button');
			toast.setAttribute('tabindex', '0');
			toast.setAttribute('aria-pressed', 'false');
			toast.setAttribute('aria-label', previewAriaLabel(type));
			toast.innerHTML =
				'<div class="mw-sales-toast__media" hidden></div>' +
				'<div class="mw-sales-toast__body">' +
				'<p class="mw-sales-toast__text"></p>' +
				'<p class="mw-sales-toast__meta"></p>' +
				'<div class="mw-sales-toast__cta" hidden></div>' +
				'</div>' +
				'<span class="mw-sales-toast__close" aria-hidden="true">×</span>';

			item.appendChild(caption);
			item.appendChild(toast);
			sampleList.appendChild(item);

			toast.addEventListener('click', function () {
				previewSample(type);
			});
			toast.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					previewSample(type);
				}
			});
		});
	}

	function syncSample() {
		var types = selectedPreviewTypes();
		if (!sampleList) {
			return;
		}
		var key = types.join(',');
		if (sampleList.getAttribute('data-types') !== key) {
			rebuildSampleList(types);
			sampleList.setAttribute('data-types', key);
		}
		sampleList.querySelectorAll('.mwst-sample-item').forEach(function (item) {
			var type = item.getAttribute('data-preview-type');
			var toast = item.querySelector('.mwst-sample-toast');
			var caption = item.querySelector('.mwst-preview-type');
			fillToastEl(toast, type);
			item.classList.toggle('is-previewing', liveVisible && previewType === type);
			if (toast) {
				toast.setAttribute(
					'aria-pressed',
					liveVisible && previewType === type ? 'true' : 'false'
				);
				toast.setAttribute('aria-label', previewAriaLabel(type));
			}
			if (caption) {
				caption.hidden = types.length < 2;
				caption.textContent = previewTypeTitle(type);
			}
		});
		if (liveVisible && liveEl) {
			fillToastEl(liveEl, previewType);
			syncLivePosition();
		}
	}

	function markPreviewingSample() {
		if (!sampleList) {
			return;
		}
		sampleList.querySelectorAll('.mwst-sample-item').forEach(function (item) {
			var on = liveVisible && item.getAttribute('data-preview-type') === previewType;
			item.classList.toggle('is-previewing', on);
			var toast = item.querySelector('.mwst-sample-toast');
			if (toast) {
				toast.setAttribute('aria-pressed', on ? 'true' : 'false');
			}
		});
	}

	function syncPositionCaption() {
		if (posCaption) {
			posCaption.textContent = posLabels[currentPosition()] || currentPosition();
		}
	}

	function syncLivePosition() {
		if (!liveEl) {
			return;
		}
		liveEl.className =
			'mw-sales-toast mwst-admin-live mw-sales-toast--' +
			currentPosition() +
			' mw-sales-toast--media-' +
			currentImageFit() +
			(liveVisible ? ' is-visible' : '');
		liveEl.classList.toggle('mw-sales-toast--viewing', previewType === 'viewing');
		liveEl.classList.toggle('mw-sales-toast--review', previewType === 'review');
		liveEl.classList.toggle('mw-sales-toast--cta', previewType === 'cta');
	}

	function clearHideTimer() {
		window.clearTimeout(hideTimer);
		hideTimer = null;
	}

	function ensureLiveToast() {
		if (liveEl) {
			return liveEl;
		}

		liveEl = document.createElement('aside');
		liveEl.className = 'mw-sales-toast mwst-admin-live mw-sales-toast--media-' + currentImageFit();
		liveEl.setAttribute('role', 'status');
		liveEl.setAttribute('aria-live', 'polite');
		liveEl.innerHTML =
			'<div class="mw-sales-toast__media" hidden></div>' +
			'<div class="mw-sales-toast__body">' +
			'<p class="mw-sales-toast__text"></p>' +
			'<p class="mw-sales-toast__meta"></p>' +
			'<div class="mw-sales-toast__cta" hidden></div>' +
			'</div>' +
			'<button type="button" class="mw-sales-toast__close" aria-label="' +
			escapeHtml(i18n.dismiss || 'Dismiss') +
			'">×</button>';

		document.body.appendChild(liveEl);

		liveEl.querySelector('.mw-sales-toast__close').addEventListener('click', function () {
			hideLiveToast();
		});

		return liveEl;
	}

	function playPopSample() {
		if (typeof window.mwSalesToastPlayPop === 'function') {
			try {
				window.mwSalesToastPlayPop();
			} catch (e) {
				/* ignore */
			}
		}
	}

	function playPopIfEnabled() {
		if (soundEnabled && !soundEnabled.checked) {
			return;
		}
		playPopSample();
	}

	function showLiveToast(type) {
		var types = selectedPreviewTypes();
		previewType = types.indexOf(type) !== -1 ? type : types[0] || 'sale';
		var el = ensureLiveToast();
		liveVisible = true;
		el.classList.remove('is-leaving');
		fillToastEl(el, previewType);
		syncLivePosition();
		el.classList.remove('is-visible');
		void el.offsetWidth;
		el.classList.add('is-visible');
		playPopIfEnabled();
		schedulePreviewHide();
		markPreviewingSample();
	}

	function schedulePreviewHide() {
		clearHideTimer();
		hideTimer = window.setTimeout(function () {
			hideTimer = null;
			if (liveVisible) {
				hideLiveToast();
			}
		}, currentDurationMs());
	}

	function hideLiveToast() {
		clearHideTimer();
		if (liveEl) {
			liveEl.classList.add('is-leaving');
			liveEl.classList.remove('is-visible');
			window.setTimeout(function () {
				if (liveEl) {
					liveEl.classList.remove('is-leaving');
				}
			}, 400);
		}
		liveVisible = false;
		markPreviewingSample();
	}

	function previewSample(type) {
		if (liveVisible && previewType === type) {
			hideLiveToast();
			return;
		}
		showLiveToast(type);
	}

	function designValue(key, fallback) {
		var input = root.querySelector('.mwst-design-input[data-design="' + key + '"]');
		if (!input) {
			return fallback;
		}
		var value = String(input.value || '').trim();
		return value || fallback;
	}

	function currentImageFit() {
		var selected = root.querySelector('.mwst-image-fit-input:checked');
		return selected && selected.value === 'padded' ? 'padded' : 'full';
	}

	function applyMediaFitClass(el) {
		if (!el) {
			return;
		}
		var fit = currentImageFit();
		el.classList.remove('mw-sales-toast--media-full', 'mw-sales-toast--media-padded');
		el.classList.add('mw-sales-toast--media-' + fit);
	}

	function syncImageFitState() {
		var on = !!(showImage && showImage.checked);
		imageFitInputs.forEach(function (input) {
			input.disabled = !on;
		});
		if (imageFitField) {
			imageFitField.classList.toggle('is-disabled', !on);
			if (on) {
				imageFitField.removeAttribute('data-disabled');
			} else {
				imageFitField.setAttribute('data-disabled', '1');
			}
		}
	}

	function syncImageFit() {
		if (sampleList) {
			sampleList.querySelectorAll('.mwst-sample-toast').forEach(function (toast) {
				applyMediaFitClass(toast);
			});
		}
		if (liveEl) {
			applyMediaFitClass(liveEl);
		}
		syncDesign();
	}

	function hexToRgba(hex, opacity) {
		var raw = String(hex || '').replace('#', '');
		if (raw.length === 3) {
			raw = raw[0] + raw[0] + raw[1] + raw[1] + raw[2] + raw[2];
		}
		if (!/^[0-9a-fA-F]{6}$/.test(raw)) {
			return hex;
		}
		var r = parseInt(raw.slice(0, 2), 16);
		var g = parseInt(raw.slice(2, 4), 16);
		var b = parseInt(raw.slice(4, 6), 16);
		var a = Math.max(0, Math.min(100, parseInt(opacity, 10) || 0)) / 100;
		a = Math.round(a * 100) / 100;
		return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
	}

	function isElementorThemeActive() {
		return !!(
			useElementorTheme &&
			useElementorTheme.checked &&
			elementorThemeCfg.available &&
			elementorThemeCfg.theme
		);
	}

	function syncElementorThemeUi() {
		var active = !!(useElementorTheme && useElementorTheme.checked && elementorThemeCfg.available);
		var hasTheme = !!(elementorThemeCfg.theme);
		var lockColors = active && hasTheme;

		if (customColorsField) {
			if (lockColors) {
				customColorsField.classList.add('is-disabled');
				customColorsField.setAttribute('data-disabled', '1');
			} else {
				customColorsField.classList.remove('is-disabled');
				customColorsField.removeAttribute('data-disabled');
			}
		}
		root.querySelectorAll('.mwst-color-picker').forEach(function (input) {
			input.disabled = lockColors;
			var wrap = input.closest('.wp-picker-container');
			if (wrap) {
				wrap.classList.toggle('mwst-picker-locked', lockColors);
			}
		});
		// Opacity stays editable when Elementor colors are used.
		root.querySelectorAll('#mwst-style-bg-opacity, #mwst-style-border-opacity').forEach(function (input) {
			input.disabled = false;
		});
		syncDesign();
	}

	function shadowCss(key) {
		var map = {
			none: 'none',
			soft: '0 1px 0 rgba(255,255,255,0.04) inset, 0 8px 20px rgba(0,0,0,0.18)',
			medium: '0 1px 0 rgba(255,255,255,0.05) inset, 0 18px 40px rgba(0,0,0,0.45)',
			strong: '0 1px 0 rgba(255,255,255,0.06) inset, 0 28px 56px rgba(0,0,0,0.6)'
		};
		return map[key] || map.medium;
	}

	function buildDesignCss() {
		var theme = isElementorThemeActive() ? elementorThemeCfg.theme : null;
		var colorVal = function (key, fallback) {
			if (theme && theme[key]) {
				return theme[key];
			}
			return designValue(key, fallback);
		};
		var radius = parseInt(designValue('style_radius', designDefaults.style_radius || 14), 10);
		var padding = parseInt(designValue('style_padding', designDefaults.style_padding || 12), 10);
		var maxWidth = parseInt(designValue('style_max_width', designDefaults.style_max_width || 360), 10);
		var shadow = shadowCss(designValue('style_shadow', designDefaults.style_shadow || 'medium'));
		var imageFit = currentImageFit();
		var bgOpacity = parseInt(
			designValue('style_bg_opacity', designDefaults.style_bg_opacity || 92),
			10
		);
		var borderOpacity = parseInt(
			designValue('style_border_opacity', designDefaults.style_border_opacity || 10),
			10
		);
		if (isNaN(radius)) {
			radius = 14;
		}
		if (isNaN(padding)) {
			padding = 12;
		}
		if (isNaN(maxWidth)) {
			maxWidth = 360;
		}
		if (isNaN(bgOpacity)) {
			bgOpacity = 92;
		}
		if (isNaN(borderOpacity)) {
			borderOpacity = 10;
		}
		var offsetX = parseInt(designValue('offset_x', 20), 10);
		var offsetY = parseInt(designValue('offset_y', 20), 10);
		if (isNaN(offsetX)) {
			offsetX = 20;
		}
		if (isNaN(offsetY)) {
			offsetY = 20;
		}
		radius = Math.max(0, Math.min(40, radius));
		padding = Math.max(4, Math.min(32, padding));
		maxWidth = Math.max(220, Math.min(560, maxWidth));
		offsetX = Math.max(0, Math.min(80, offsetX));
		offsetY = Math.max(0, Math.min(80, offsetY));
		var mediaRadius =
			imageFit === 'padded' ? Math.max(0, Math.min(24, Math.round(radius * 0.7))) : 0;

		var fontCss = '';
		if (theme && theme.font) {
			fontCss = '--mw-st-font:"' + String(theme.font).replace(/"/g, '') + '",system-ui,sans-serif;';
		}

		var css =
			'.mw-sales-toast{' +
			fontCss +
			'--mw-st-bg:' +
			hexToRgba(colorVal('style_bg', designDefaults.style_bg), bgOpacity) +
			';' +
			'--mw-st-color:' +
			colorVal('style_meta', designDefaults.style_meta) +
			';' +
			'--mw-st-body:' +
			colorVal('style_body', designDefaults.style_body) +
			';' +
			'--mw-st-accent:' +
			colorVal('style_accent', designDefaults.style_accent) +
			';' +
			'--mw-st-meta:' +
			colorVal('style_meta', designDefaults.style_meta) +
			';' +
			'--mw-st-close-hover:' +
			colorVal('style_close_hover', designDefaults.style_close_hover || '#dc2626') +
			';' +
			'--mw-st-border:' +
			hexToRgba(colorVal('style_border', designDefaults.style_border), borderOpacity) +
			';' +
			'--mw-st-radius:' +
			radius +
			'px;' +
			'--mw-st-media-radius:' +
			mediaRadius +
			'px;' +
			'--mw-st-padding:' +
			padding +
			'px;' +
			'--mw-st-max-width:' +
			maxWidth +
			'px;' +
			'--mw-st-offset-x:' +
			offsetX +
			'px;' +
			'--mw-st-offset-y:' +
			offsetY +
			'px;' +
			'--mw-st-shadow:' +
			shadow +
			';}';

		var custom = designValue('custom_css', '');
		if (custom) {
			css += '\n' + custom;
		}
		return css;
	}

	function ensureDesignStyle() {
		if (designStyleEl) {
			return designStyleEl;
		}
		designStyleEl = document.getElementById('mwst-design-live');
		if (!designStyleEl) {
			designStyleEl = document.createElement('style');
			designStyleEl.id = 'mwst-design-live';
			document.head.appendChild(designStyleEl);
		}
		return designStyleEl;
	}

	function syncDesign() {
		ensureDesignStyle().textContent = buildDesignCss();
	}

	function setDesignInputValue(key, value) {
		var input = root.querySelector('.mwst-design-input[data-design="' + key + '"]');
		if (!input) {
			return;
		}
		if (key === 'custom_css') {
			var cssVal = String(value == null ? '' : value);
			if (!cssVal.trim() && cfg.customCssExample) {
				cssVal = String(cfg.customCssExample);
			}
			input.value = cssVal;
			if (cssCodeMirror) {
				cssCodeMirror.setValue(cssVal);
			}
			return;
		}
		input.value = value;
		if (window.jQuery && input.classList.contains('mwst-color-picker')) {
			window.jQuery(input).wpColorPicker('color', value);
		}
		if (input.classList.contains('mwst-opacity-slider')) {
			syncOpacityValueLabel(input);
		}
	}

	function syncOpacityValueLabel(input) {
		if (!input) {
			return;
		}
		var wrap = input.closest('.mwst-opacity-field');
		var label = wrap ? wrap.querySelector('[data-opacity-value]') : null;
		if (label) {
			label.textContent = String(parseInt(input.value, 10) || 0) + '%';
		}
	}

	function selectDesignPreset(key) {
		var input = root.querySelector(
			'.mwst-design-preset-input[value="' + key + '"]'
		);
		if (!input) {
			return;
		}
		input.checked = true;
		root.querySelectorAll('.mwst-design-preset').forEach(function (label) {
			var radio = label.querySelector('.mwst-design-preset-input');
			label.classList.toggle('is-active', !!(radio && radio.checked));
		});
	}

	function markDesignPresetCustom() {
		if (applyingDesignPreset) {
			return;
		}
		selectDesignPreset('custom');
	}

	function finishApplyingDesignPreset(presetKey) {
		selectDesignPreset(presetKey);
		syncElementorThemeUi();
		// wpColorPicker change handlers use setTimeout(10); keep the guard until after them.
		window.setTimeout(function () {
			applyingDesignPreset = false;
			selectDesignPreset(presetKey);
		}, 50);
	}

	function applyDesignPresetFromInput(input) {
		if (!input || input.value === 'custom') {
			selectDesignPreset('custom');
			syncDesign();
			return;
		}

		var presetKey = input.value;
		applyingDesignPreset = true;

		// Choosing a built-in theme turns off Elementor kit sync.
		if (useElementorTheme && useElementorTheme.checked) {
			useElementorTheme.checked = false;
		}

		var map = {
			style_bg: 'data-style-bg',
			style_bg_opacity: 'data-style-bg-opacity',
			style_text: 'data-style-text',
			style_body: 'data-style-body',
			style_accent: 'data-style-accent',
			style_meta: 'data-style-meta',
			style_close_hover: 'data-style-close-hover',
			style_border: 'data-style-border',
			style_border_opacity: 'data-style-border-opacity',
			style_radius: 'data-style-radius'
		};

		Object.keys(map).forEach(function (key) {
			var raw = input.getAttribute(map[key]);
			if (raw === null || raw === '') {
				return;
			}
			setDesignInputValue(key, raw);
		});

		finishApplyingDesignPreset(presetKey);
	}

	function syncDesignPresetUi() {
		root.querySelectorAll('.mwst-design-preset').forEach(function (label) {
			var radio = label.querySelector('.mwst-design-preset-input');
			label.classList.toggle('is-active', !!(radio && radio.checked));
		});
	}

	function resetDesign() {
		applyingDesignPreset = true;
		if (useElementorTheme && useElementorTheme.checked) {
			useElementorTheme.checked = false;
		}
		Object.keys(designDefaults).forEach(function (key) {
			setDesignInputValue(key, designDefaults[key]);
		});
		finishApplyingDesignPreset('midnight');
		refreshDirtyFromForm();
	}

	function initColorPickers() {
		if (!window.jQuery || !window.jQuery.fn.wpColorPicker) {
			return;
		}
		window.jQuery(root)
			.find('.mwst-color-picker')
			.each(function () {
				var $input = window.jQuery(this);
				$input.wpColorPicker({
					change: function () {
						window.setTimeout(function () {
							markDesignPresetCustom();
							syncDesign();
							refreshDirtyFromForm();
						}, 10);
					},
					clear: function () {
						window.setTimeout(function () {
							markDesignPresetCustom();
							syncDesign();
							refreshDirtyFromForm();
						}, 10);
					}
				});
			});
	}

	root.querySelectorAll('.mwst-token').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var targetId = btn.getAttribute('data-target') || 'mwst-template';
			var field = root.querySelector('#' + targetId);
			if (!field) {
				return;
			}
			var token = btn.getAttribute('data-token');
			var start = field.selectionStart;
			var end = field.selectionEnd;
			var value = field.value;
			field.value = value.slice(0, start) + token + value.slice(end);
			field.focus();
			var caret = start + token.length;
			if (typeof field.setSelectionRange === 'function') {
				field.setSelectionRange(caret, caret);
			}
			syncSample();
			if (typeof field.dispatchEvent === 'function') {
				field.dispatchEvent(new Event('input', { bubbles: true }));
			} else {
				refreshDirtyFromForm();
			}
		});
	});

	if (enabled) {
		enabled.addEventListener('change', syncBadge);
	}
	if (template) {
		template.addEventListener('input', syncSample);
	}
	if (fallback) {
		fallback.addEventListener('input', function () {
			syncSample();
		});
	}
	if (hideNames) {
		hideNames.addEventListener('change', syncSample);
	}
	if (showImage) {
		showImage.addEventListener('change', function () {
			syncImageFitState();
			syncSample();
		});
	}
	if (whenStyle) {
		whenStyle.addEventListener('change', syncSample);
	}

	if (viewingMode) {
		viewingMode.addEventListener('change', function () {
			syncViewingModeOptions();
			syncSample();
		});
	}

	[
		'#mwst-viewing-template',
		'#mwst-viewing-min',
		'#mwst-viewing-max',
		'#mwst-review-template',
		'#mwst-review-excerpt',
		'#mwst-cta-message',
		'#mwst-cta-coupon',
		'#mwst-cta-button',
		'#mwst-cta-url'
	].forEach(function (selector) {
		var el = root.querySelector(selector);
		if (!el) {
			return;
		}
		el.addEventListener('input', syncSample);
		el.addEventListener('change', syncSample);
	});
	root.querySelectorAll('#mwst-type-viewing-opt input, #mwst-type-review-opt input').forEach(function (el) {
		el.addEventListener('input', syncSample);
		el.addEventListener('change', syncSample);
	});
	function syncStockThresholdState() {
		var off = !stockDisplay || stockDisplay.value === 'off';
		if (stockThreshold) {
			stockThreshold.disabled = off;
		}
		if (stockThresholdField) {
			stockThresholdField.classList.toggle('is-disabled', off);
			if (off) {
				stockThresholdField.setAttribute('data-disabled', '1');
			} else {
				stockThresholdField.removeAttribute('data-disabled');
			}
		}
	}

	function syncExcludeHomeState() {
		var homeOnly = showOnSelect && showOnSelect.value === 'home';
		if (!excludeHomeField) {
			return;
		}
		excludeHomeField.hidden = !!homeOnly;
	}

	function syncMobileBreakpointState() {
		var off = !disableMobile || !disableMobile.checked;
		if (mobileBreakpoint) {
			mobileBreakpoint.disabled = off;
		}
		if (mobileBreakpointField) {
			mobileBreakpointField.classList.toggle('is-disabled', off);
			if (off) {
				mobileBreakpointField.setAttribute('data-disabled', '1');
			} else {
				mobileBreakpointField.removeAttribute('data-disabled');
			}
		}
	}

	if (stockDisplay) {
		stockDisplay.addEventListener('change', function () {
			syncStockThresholdState();
			syncSample();
		});
	}
	if (showOnSelect) {
		showOnSelect.addEventListener('change', syncExcludeHomeState);
	}
	if (disableMobile) {
		disableMobile.addEventListener('change', syncMobileBreakpointState);
	}

	function initCustomCssEditor() {
		var ta = root.querySelector('#mwst-custom-css');
		if (!ta || !cfg.codeEditor || !window.wp || !wp.codeEditor) {
			return;
		}
		var instance = wp.codeEditor.initialize(ta, cfg.codeEditor);
		if (!instance || !instance.codemirror) {
			return;
		}
		cssCodeMirror = instance.codemirror;
		cssCodeMirror.on('change', function () {
			cssCodeMirror.save();
			syncDesign();
			refreshDirtyFromForm();
		});
	}

	initCustomCssEditor();

	var customCssCard = root.querySelector('#mwst-custom-css-card');
	if (customCssCard) {
		customCssCard.addEventListener('toggle', function () {
			if (customCssCard.open && cssCodeMirror) {
				window.setTimeout(function () {
					cssCodeMirror.refresh();
				}, 0);
			}
		});
	}

	designInputs.forEach(function (input) {
		input.addEventListener('input', function () {
			if (input.classList.contains('mwst-opacity-slider')) {
				syncOpacityValueLabel(input);
			}
			if (
				input.getAttribute('data-design') === 'custom_css' ||
				input.getAttribute('data-design') === 'style_max_width' ||
				input.getAttribute('data-design') === 'style_padding' ||
				input.getAttribute('data-design') === 'style_shadow' ||
				input.getAttribute('data-design') === 'offset_x' ||
				input.getAttribute('data-design') === 'offset_y'
			) {
				syncDesign();
				return;
			}
			markDesignPresetCustom();
			syncDesign();
		});
		input.addEventListener('change', function () {
			if (input.classList.contains('mwst-opacity-slider')) {
				syncOpacityValueLabel(input);
			}
			if (
				input.getAttribute('data-design') === 'custom_css' ||
				input.getAttribute('data-design') === 'style_max_width' ||
				input.getAttribute('data-design') === 'style_padding' ||
				input.getAttribute('data-design') === 'style_shadow' ||
				input.getAttribute('data-design') === 'offset_x' ||
				input.getAttribute('data-design') === 'offset_y'
			) {
				syncDesign();
				return;
			}
			markDesignPresetCustom();
			syncDesign();
		});
	});

	designPresetInputs.forEach(function (input) {
		input.addEventListener('change', function () {
			applyDesignPresetFromInput(input);
		});
	});

	imageFitInputs.forEach(function (input) {
		input.addEventListener('change', syncImageFit);
	});

	if (useElementorTheme) {
		useElementorTheme.addEventListener('change', function () {
			if (useElementorTheme.checked) {
				selectDesignPreset('custom');
			}
			syncElementorThemeUi();
		});
	}

	if (resetDesignBtn) {
		resetDesignBtn.addEventListener('click', resetDesign);
	}

	if (testSoundBtn) {
		testSoundBtn.addEventListener('click', playPopSample);
	}

	function setSupportStatus(type, message) {
		if (!supportStatus) {
			return;
		}
		supportStatus.hidden = !message;
		supportStatus.textContent = message || '';
		supportStatus.classList.remove('is-success', 'is-error');
		if (type) {
			supportStatus.classList.add('is-' + type);
		}
	}

	var cacheRebuildBtn = root.querySelector('#mwst-cache-rebuild');
	var cacheRebuildStatus = root.querySelector('#mwst-cache-rebuild-status');
	if (cacheRebuildBtn) {
		cacheRebuildBtn.addEventListener('click', function () {
			if (!cacheCfg.ajaxUrl) {
				return;
			}
			cacheRebuildBtn.disabled = true;
			cacheRebuildBtn.textContent = i18n.cacheRebuilding || 'Rebuilding cache…';
			if (cacheRebuildStatus) {
				cacheRebuildStatus.classList.remove('is-ok', 'is-error');
				cacheRebuildStatus.textContent = i18n.cacheRebuilding || 'Rebuilding cache…';
			}

			var body = new window.FormData();
			body.append('action', cacheCfg.action || 'mw_st_rebuild_cache');
			body.append('nonce', cacheCfg.nonce || '');

			window
				.fetch(cacheCfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, data: data };
					});
				})
				.then(function (result) {
					var payload = result.data || {};
					var inner = payload.data || {};
					var msg =
						inner.message ||
						payload.message ||
						i18n.cacheRebuildError ||
						'Could not rebuild the cache. Please try again.';
					if (payload.success) {
						if (cacheRebuildStatus) {
							cacheRebuildStatus.classList.add('is-ok');
							cacheRebuildStatus.classList.remove('is-error');
							cacheRebuildStatus.textContent = msg;
						}
						var ttlEl = root.querySelector('#mwst-cache-ttl');
						if (ttlEl && inner.ttl) {
							ttlEl.textContent = inner.ttl;
						}
						if (typeof inner.events === 'number') {
							var eventsEl = root.querySelector('#mwst-header-events');
							if (eventsEl) {
								var maxCached = parseInt(inner.max, 10);
								if (!maxCached) {
									maxCached = parseInt(eventsEl.getAttribute('data-max'), 10);
								}
								if (!maxCached) {
									var maxInput = root.querySelector('#mwst-cached');
									maxCached = maxInput ? parseInt(maxInput.value, 10) : 0;
								}
								function fmtNum(n) {
									try {
										return Number(n).toLocaleString();
									} catch (e) {
										return String(n);
									}
								}
								eventsEl.textContent = maxCached
									? fmtNum(inner.events) + '/' + fmtNum(maxCached)
									: fmtNum(inner.events);
								if (maxCached) {
									eventsEl.setAttribute('data-max', String(maxCached));
									eventsEl.setAttribute(
										'aria-label',
										fmtNum(inner.events) + ' of ' + fmtNum(maxCached) + ' cached events'
									);
								}
								eventsEl.classList.toggle('is-ok', inner.events > 0);
								eventsEl.classList.toggle('is-bad', inner.events < 1);
							}
							if (inner.events > 0) {
								var emptyNotice = root.querySelector('#mwst-empty-cache-notice');
								if (emptyNotice) {
									emptyNotice.remove();
								}
							}
						}
					} else if (cacheRebuildStatus) {
						cacheRebuildStatus.classList.add('is-error');
						cacheRebuildStatus.classList.remove('is-ok');
						cacheRebuildStatus.textContent = msg;
					}
				})
				.catch(function () {
					if (cacheRebuildStatus) {
						cacheRebuildStatus.classList.add('is-error');
						cacheRebuildStatus.classList.remove('is-ok');
						cacheRebuildStatus.textContent =
							i18n.cacheRebuildError || 'Could not rebuild the cache. Please try again.';
					}
				})
				.finally(function () {
					cacheRebuildBtn.disabled = false;
					cacheRebuildBtn.textContent = i18n.cacheRebuild || 'Rebuild cache';
				});
		});
	}

	if (supportSubmit) {
		supportSubmit.addEventListener('click', function () {
			if (!supportCfg.ajaxUrl) {
				return;
			}

			var name = ((root.querySelector('#mwst-support-name') || {}).value || '').trim();
			var email = ((root.querySelector('#mwst-support-email') || {}).value || '').trim();
			var subject = ((root.querySelector('#mwst-support-subject') || {}).value || '').trim();
			var message = ((root.querySelector('#mwst-support-message') || {}).value || '').trim();
			var includeSystem = !!(
				root.querySelector('#mwst-support-system') &&
				root.querySelector('#mwst-support-system').checked
			);

			setSupportStatus('', '');
			supportSubmit.disabled = true;
			supportSubmit.textContent = i18n.supportSending || 'Sending…';

			var body = new window.FormData();
			body.append('action', supportCfg.action || 'mw_st_support_request');
			body.append('nonce', supportCfg.nonce || '');
			body.append('name', name);
			body.append('email', email);
			body.append('subject', subject);
			body.append('message', message);
			if (includeSystem) {
				body.append('include_system', '1');
			}

			window
				.fetch(supportCfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, data: data };
					});
				})
				.then(function (result) {
					var payload = result.data || {};
					var msg =
						(payload.data && payload.data.message) ||
						payload.message ||
						i18n.supportError ||
						'Something went wrong. Please try again.';
					if (payload.success) {
						setSupportStatus('success', msg);
						var subjectEl = root.querySelector('#mwst-support-subject');
						var messageEl = root.querySelector('#mwst-support-message');
						if (subjectEl) {
							subjectEl.value = '';
						}
						if (messageEl) {
							messageEl.value = '';
						}
					} else {
						setSupportStatus('error', msg);
					}
				})
				.catch(function () {
					setSupportStatus(
						'error',
						i18n.supportError || 'Something went wrong. Please try again.'
					);
				})
				.finally(function () {
					supportSubmit.disabled = false;
					supportSubmit.textContent = i18n.supportSend || 'Send message';
				});
		});
	}

	initColorPickers();

	if (dismissNotice && savedNotice) {
		dismissNotice.addEventListener('click', function () {
			savedNotice.remove();
		});
	}

	if (savedNotice) {
		window.setTimeout(function () {
			if (savedNotice && savedNotice.parentNode) {
				savedNotice.style.transition = 'opacity 0.35s ease, transform 0.35s ease';
				savedNotice.style.opacity = '0';
				savedNotice.style.transform = 'translateY(-6px)';
				window.setTimeout(function () {
					if (savedNotice && savedNotice.parentNode) {
						savedNotice.remove();
					}
				}, 350);
			}
		}, 8000);
	}

	root.querySelectorAll('input[name$="[position]"]').forEach(function (input) {
		input.addEventListener('change', function () {
			syncPositionCaption();
			if (liveVisible) {
				syncLivePosition();
			}
		});
	});

	if (durationInputField) {
		durationInputField.addEventListener('change', function () {
			if (liveVisible) {
				showLiveToast(previewType);
			}
		});
	}

	timingPresets.forEach(function (input) {
		input.addEventListener('change', syncTimingPreset);
	});

	[delayInput, durationInputField, gapInput, jitterInput, maxEventsInput].forEach(function (el) {
		if (!el) {
			return;
		}
		el.addEventListener('input', updateCycleEstimate);
		el.addEventListener('change', updateCycleEstimate);
	});

	triggerInputs.forEach(function (input) {
		input.addEventListener('change', syncTriggerOptions);
	});

	typeInputs.forEach(function (input) {
		input.addEventListener('change', syncTypeOptions);
	});

	syncBadge();
	syncStockThresholdState();
	syncExcludeHomeState();
	syncMobileBreakpointState();
	syncSample();
	syncPositionCaption();

	root.querySelectorAll('.mwst-path-example').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var targetId = btn.getAttribute('data-target') || '';
			var path = btn.getAttribute('data-path') || '';
			var area = targetId ? root.querySelector('#' + targetId) : null;
			if (!area || !path) {
				return;
			}
			var current = String(area.value || '').replace(/\s+$/, '');
			area.value = current ? current + '\n' + path : path;
			area.dispatchEvent(new Event('input', { bubbles: true }));
			area.focus();
		});
	});

	var requestedTab = '';
	try {
		requestedTab = new URL(window.location.href).searchParams.get('tab') || '';
	} catch (err) {
		requestedTab = '';
	}

	activateTab(tabFromUrl());
	refreshProductSelects();
	if (typeof window.jQuery !== 'undefined') {
		window.jQuery(document).on('wc-enhanced-select-init', refreshProductSelects);
	}
	window.addEventListener('load', refreshProductSelects);

	if (requestedTab === 'contact' && !window.location.hash) {
		try {
			var contactUrl = new URL(window.location.href);
			contactUrl.searchParams.set('tab', 'support');
			contactUrl.hash = 'mwst-support-contact';
			window.history.replaceState(
				{},
				'',
				contactUrl.pathname + contactUrl.search + contactUrl.hash
			);
		} catch (err2) {
			window.location.hash = 'mwst-support-contact';
		}
		scrollToHashTarget();
	} else if (requestedTab === 'demo') {
		var demoFold = root.querySelector('#mwst-demo-fold');
		if (demoFold) {
			demoFold.open = true;
		}
		try {
			var demoUrl = new URL(window.location.href);
			demoUrl.searchParams.set('tab', 'message');
			demoUrl.hash = 'mwst-demo-fold';
			window.history.replaceState(
				{},
				'',
				demoUrl.pathname + demoUrl.search + demoUrl.hash
			);
		} catch (errDemo) {
			window.location.hash = 'mwst-demo-fold';
		}
		scrollToHashTarget();
	} else if (requestedTab === 'license' && !window.location.hash) {
		try {
			var accountUrl = new URL(window.location.href);
			accountUrl.searchParams.set('tab', 'account');
			window.history.replaceState(
				{},
				'',
				accountUrl.pathname + accountUrl.search + accountUrl.hash
			);
		} catch (err3) {
			// Ignore URL sync failures.
		}
		scrollToHashTarget();
	} else if (window.location.hash) {
		scrollToHashTarget();
	}

	syncImageFitState();
	syncImageFit();
	syncElementorThemeUi();
	syncTimingPreset();
	syncTriggerOptions();
	syncTypeOptions();

	/* Statistics date range — live aggregates when analytics payload exists. */
	var analyticsData = cfg.analytics || null;
	function formatStatNumber(n) {
		n = Number(n) || 0;
		try {
			return n.toLocaleString();
		} catch (e) {
			return String(n);
		}
	}

	function applyStats(summary) {
		if (!summary) {
			return;
		}
		var panel = root.querySelector('#mwst-panel-statistics');
		if (!panel) {
			return;
		}
		var impressions = Number(summary.impressions) || 0;
		var map = {
			impressions: formatStatNumber(impressions),
			clicks: formatStatNumber(summary.clicks),
			ctr: (Number(summary.ctr) || 0) + '%',
			atc: formatStatNumber(summary.atc),
			dismissed: formatStatNumber(summary.dismissed),
			muted: formatStatNumber(summary.muted),
			purchases: formatStatNumber(summary.purchases),
			attrWindow:
				String(Number(summary.attrWindow) || 30) +
				' ' +
				(i18n.statsMinutes || 'minutes')
		};
		Object.keys(map).forEach(function (key) {
			panel.querySelectorAll('[data-stat="' + key + '"]').forEach(function (el) {
				el.textContent = map[key];
			});
		});
		var deltas = summary.delta || {};
		['impressions', 'clicks', 'ctr', 'atc'].forEach(function (key) {
			var d = deltas[key] || {};
			panel.querySelectorAll('[data-stat-delta="' + key + '"]').forEach(function (el) {
				el.textContent = d.label || 'vs prior';
				el.classList.remove('is-up', 'is-flat');
				el.classList.add(d.dir === 'up' ? 'is-up' : 'is-flat');
			});
		});
		var denom = Math.max(impressions, 1);
		panel.querySelectorAll('[data-stat-bar]').forEach(function (el) {
			var key = el.getAttribute('data-stat-bar');
			var val = Number(summary[key]) || 0;
			if (key === 'impressions') {
				el.style.width = '100%';
			} else {
				el.style.width = Math.min(100, Math.round((val / denom) * 1000) / 10) + '%';
			}
		});
		var tbody = panel.querySelector('#mwst-stats-products-body');
		if (tbody) {
			var products = summary.products || [];
			if (!products.length) {
				tbody.innerHTML =
					'<tr class="mwst-stats-empty"><td colspan="6">' +
					(i18n.statsEmpty || 'No product data yet.') +
					'</td></tr>';
			} else {
				tbody.innerHTML = products
					.map(function (p) {
						var name = p.name || '#' + p.id;
						var nameHtml = p.editUrl
							? '<a href="' +
							  String(p.editUrl).replace(/"/g, '&quot;') +
							  '">' +
							  String(name)
									.replace(/&/g, '&amp;')
									.replace(/</g, '&lt;') +
							  '</a>'
							: String(name)
									.replace(/&/g, '&amp;')
									.replace(/</g, '&lt;');
						var thumb = p.thumb
							? '<img src="' +
							  String(p.thumb).replace(/"/g, '&quot;') +
							  '" alt="" width="32" height="32" />'
							: '';
						return (
							'<tr><td><span class="mwst-stats-product"><span class="mwst-stats-product__thumb">' +
							thumb +
							'</span><span class="mwst-stats-product__name">' +
							nameHtml +
							'</span></span></td>' +
							'<td class="is-num">' +
							formatStatNumber(p.impressions) +
							'</td>' +
							'<td class="is-num">' +
							formatStatNumber(p.clicks) +
							'</td>' +
							'<td class="is-num">' +
							(Number(p.ctr) || 0) +
							'%</td>' +
							'<td class="is-num">' +
							formatStatNumber(p.carts) +
							'</td>' +
							'<td class="is-num">' +
							formatStatNumber(p.orders) +
							'</td></tr>'
						);
					})
					.join('');
			}
		}
		var typesBody = panel.querySelector('#mwst-stats-types-body');
		if (typesBody) {
			var types = summary.types || [];
			if (!types.length) {
				typesBody.innerHTML =
					'<tr class="mwst-stats-empty"><td colspan="4">' +
					(i18n.statsEmpty || 'No product data yet.') +
					'</td></tr>';
			} else {
				typesBody.innerHTML = types
					.map(function (row) {
						var label = String(row.label || row.id || '')
							.replace(/&/g, '&amp;')
							.replace(/</g, '&lt;');
						return (
							'<tr><th scope="row">' +
							label +
							'</th>' +
							'<td class="is-num">' +
							formatStatNumber(row.impressions) +
							'</td>' +
							'<td class="is-num">' +
							formatStatNumber(row.clicks) +
							'</td>' +
							'<td class="is-num">' +
							(Number(row.ctr) || 0) +
							'%</td></tr>'
						);
					})
					.join('');
			}
		}
	}

	root.querySelectorAll('.mwst-stats-range').forEach(function (group) {
		group.querySelectorAll('.mwst-stats-range__btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				group.querySelectorAll('.mwst-stats-range__btn').forEach(function (other) {
					var on = other === btn;
					other.classList.toggle('is-active', on);
					other.setAttribute('aria-pressed', on ? 'true' : 'false');
				});
				if (analyticsData) {
					var range = btn.getAttribute('data-range') || '7';
					applyStats(analyticsData[range] || analyticsData['7']);
				}
			});
		});
	});
	if (analyticsData) {
		applyStats(analyticsData['7'] || analyticsData[7]);
	}

	root.querySelectorAll('.mwst-transfer__submit').forEach(function (btn) {
		var fileId = btn.getAttribute('data-mwst-file') || '';
		var formId = btn.getAttribute('data-mwst-form') || '';
		var file = fileId ? document.getElementById(fileId) : null;
		var form = formId ? document.getElementById(formId) : null;
		var wrap = btn.closest('.mwst-transfer__import');
		var spinner = wrap ? wrap.querySelector('.mwst-transfer__spinner') : null;
		var idleLabel = btn.textContent;

		function setBusy(on) {
			if (wrap) {
				wrap.classList.toggle('is-busy', !!on);
			}
			btn.disabled = !!on;
			if (spinner) {
				spinner.hidden = !on;
			}
			btn.textContent = on ? i18n.transferImporting || 'Importing…' : idleLabel;
		}

		btn.addEventListener('click', function () {
			if (!file || btn.disabled) {
				return;
			}
			file.value = '';
			file.click();
		});

		if (file) {
			file.addEventListener('change', function () {
				if (!file.value) {
					return;
				}
				var msg = btn.getAttribute('data-mwst-confirm') || '';
				if (msg && !window.confirm(msg)) {
					file.value = '';
					return;
				}
				if (!form) {
					return;
				}
				setBusy(true);
				form.submit();
			});
		}
	});

	captureSaveSnapshot(true);
	window.setTimeout(function () {
		if (!userTouchedForm) {
			captureSaveSnapshot(true);
		}
	}, 500);
})();
