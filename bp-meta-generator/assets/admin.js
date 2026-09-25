/* global jQuery, bpmgData, wp, tinymce */
(function ($) {
	'use strict';

	var d = window.bpmgData || {};
	var t = d.i18n || {};

	function fmt(str, args) {
		var i = 0;
		return String(str).replace(/%(\d+)\$d/g, function (m, n) {
			return args[parseInt(n, 10) - 1];
		}).replace(/%d/g, function () {
			return args[i++];
		});
	}

	function post(action, data) {
		return $.post(d.ajaxUrl, $.extend({ action: action, nonce: d.nonce }, data || {}));
	}

	function errorText(xhr) {
		var r = xhr && xhr.responseJSON;
		return (r && r.data && r.data.message) || (xhr && xhr.statusText) || 'error';
	}

	/* ------------------------------------------------------------------
	 * Метабокс
	 * ---------------------------------------------------------------- */

	var $box = $('#bpmg-metabox');

	if ($box.length) {
		var $field = $('#bpmg-description');
		var $count = $('#bpmg-count');
		var $status = $('#bpmg-status');
		var $buttons = $box.find('[data-bpmg-mode]');

		var updateCounter = function () {
			var len = Array.from($field.val().trim()).length;
			$count.text(len)
				.toggleClass('is-ok', len >= d.min && len <= d.max)
				.toggleClass('is-warn', len > 0 && (len < d.min || len > d.max));
		};

		// Синхронізація з полями Yoast / Rank Math у редакторі, щоб їхнє збереження не затерло наш опис.
		var syncSeoPlugin = function (text) {
			try {
				if (d.seo === 'yoast') {
					$('#yoast_wpseo_metadesc').val(text);
					if (window.wp && wp.data && wp.data.dispatch('yoast-seo/editor')) {
						var y = wp.data.dispatch('yoast-seo/editor');
						if (typeof y.updateData === 'function') {
							y.updateData({ description: text });
						}
					}
				} else if (d.seo === 'rankmath') {
					$('#rank_math_description').val(text);
					if (window.wp && wp.data && wp.data.dispatch('rank-math')) {
						var r = wp.data.dispatch('rank-math');
						if (typeof r.updateDescription === 'function') {
							r.updateDescription(text);
						}
					}
				}
			} catch (e) {
				// Недоступний стор SEO-плагіна — не критично, опис уже збережено на сервері.
			}
		};

		// Актуальний (можливо ще не збережений) вміст редактора.
		var editorData = function () {
			var out = {};
			try {
				if (window.wp && wp.data && wp.data.select('core/editor')) {
					var ed = wp.data.select('core/editor');
					out.title = ed.getEditedPostAttribute('title') || '';
					out.content = ed.getEditedPostContent() || '';
					return out;
				}
			} catch (e) { /* класичний редактор */ }

			out.title = $('#title').val() || '';
			if (window.tinymce && tinymce.get('content') && !tinymce.get('content').isHidden()) {
				out.content = tinymce.get('content').getContent();
			} else {
				out.content = $('#content').val() || '';
			}
			return out;
		};

		var generate = function (mode) {
			$buttons.prop('disabled', true);
			$status.removeClass('is-error').text(t.generating);

			post('bpmg_generate', $.extend({ post_id: $box.data('post-id'), mode: mode }, editorData()))
				.done(function (res) {
					if (res && res.success) {
						$field.val(res.data.description);
						updateCounter();
						syncSeoPlugin(res.data.description);
						$status.text(t.done);
					} else if (res && res.data && res.data.code === 'bpmg_exists') {
						$buttons.prop('disabled', false);
						if (window.confirm(t.confirmOver)) {
							generate('regenerate');
						} else {
							$status.text('');
						}
						return;
					} else {
						$status.addClass('is-error').text(t.error + ' ' + ((res && res.data && res.data.message) || ''));
					}
					$buttons.prop('disabled', false);
				})
				.fail(function (xhr) {
					$status.addClass('is-error').text(t.error + ' ' + errorText(xhr));
					$buttons.prop('disabled', false);
				});
		};

		$field.on('input', function () {
			updateCounter();
			syncSeoPlugin($field.val().trim());
		});

		$buttons.on('click', function () {
			var mode = $(this).data('bpmg-mode');
			if (mode === 'generate' && $field.val().trim() !== '') {
				if (!window.confirm(t.confirmOver)) {
					return;
				}
				mode = 'regenerate';
			}
			generate(mode);
		});

		updateCounter();
	}

	/* ------------------------------------------------------------------
	 * Сторінка налаштувань
	 * ---------------------------------------------------------------- */

	$('#bpmg-test-connection').on('click', function () {
		var $btn = $(this);
		var $st = $('#bpmg-test-status');
		$btn.prop('disabled', true);
		$st.removeClass('is-error is-ok').text(t.testing);
		post('bpmg_test_connection')
			.done(function (res) {
				var msg = (res && res.data && res.data.message) || '';
				$st.addClass(res && res.success ? 'is-ok' : 'is-error').text(msg);
			})
			.fail(function (xhr) {
				$st.addClass('is-error').text(errorText(xhr));
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	$('#bpmg-clear-log').on('click', function () {
		if (!window.confirm(t.confirmClear)) {
			return;
		}
		post('bpmg_clear_log').done(function () {
			window.location.reload();
		});
	});

	var $progress = $('#bpmg-bulk-progress');
	if ($progress.length) {
		var $fill = $progress.find('.bpmg-progress__fill');
		var $text = $progress.find('.bpmg-progress__text');
		var $start = $('#bpmg-bulk-start');
		var $cancel = $('#bpmg-bulk-cancel');
		var timer = null;

		var render = function (s) {
			var total = s.total || 0;
			var pct = total ? Math.min(100, Math.round((s.processed / total) * 100)) : 0;
			var tpl = s.status === 'done' ? t.statusDone : (s.status === 'cancelled' ? t.statusCancel : t.statusRun);

			$progress.prop('hidden', false).attr('data-status', s.status);
			$fill.css('width', pct + '%');
			$text.text(fmt(tpl, [s.processed, total, s.success, s.skipped, s.errors]) + (s.message ? ' — ' + t.lastError + ' ' + s.message : ''));

			var running = s.status === 'running';
			$start.prop('disabled', running);
			$cancel.prop('disabled', !running);
			return running;
		};

		var poll = function () {
			post('bpmg_bulk_status').done(function (res) {
				if (res && res.success && render(res.data)) {
					timer = setTimeout(poll, 3000);
				}
			}).fail(function () {
				timer = setTimeout(poll, 6000);
			});
		};

		$start.on('click', function () {
			if (!window.confirm(t.confirmBulk)) {
				return;
			}
			$start.prop('disabled', true);
			post('bpmg_bulk_start').done(function (res) {
				if (res && res.success) {
					render(res.data);
					clearTimeout(timer);
					timer = setTimeout(poll, 2000);
				} else {
					$start.prop('disabled', false);
					window.alert((res && res.data && res.data.message) || 'error');
				}
			}).fail(function (xhr) {
				$start.prop('disabled', false);
				window.alert(errorText(xhr));
			});
		});

		$cancel.on('click', function () {
			clearTimeout(timer);
			post('bpmg_bulk_cancel').done(function (res) {
				if (res && res.success) {
					render(res.data);
				}
			});
		});

		if ($progress.attr('data-status') !== 'idle') {
			poll();
		}
	}
})(jQuery);
