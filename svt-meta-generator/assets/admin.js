/* global jQuery, svtmgData, wp, tinymce */
(function ($) {
	'use strict';

	var d = window.svtmgData || {};
	var t = d.i18n || {};

	function fmt(str, args) {
		return String(str).replace(/%(\d+)\$d/g, function (m, n) {
			return args[parseInt(n, 10) - 1];
		});
	}

	function post(action, data) {
		return $.post(d.ajaxUrl, $.extend({ action: action, nonce: d.nonce }, data || {}));
	}

	function errorText(xhr) {
		var r = xhr && xhr.responseJSON;
		return (r && r.data && r.data.message) || (xhr && xhr.statusText) || 'error';
	}

	function editorContent(id) {
		if (window.tinymce && tinymce.get(id) && !tinymce.get(id).isHidden()) {
			return tinymce.get(id).getContent();
		}
		return $('#' + id).val() || '';
	}

	/* ------------------------------------------------------------------
	 * Поле опису (метабокс товару/запису або форма терміна)
	 * ---------------------------------------------------------------- */

	var $box = $('#svtmg-box');

	if ($box.length) {
		var object = String($box.data('object'));
		var isTerm = object.indexOf('term:') === 0;
		var $field = $('#svtmg-description');
		var $count = $('#svtmg-count');
		var $status = $('#svtmg-status');
		var $buttons = $box.find('[data-svtmg-mode]');

		var updateCounter = function () {
			var len = Array.from($field.val().trim()).length;
			$count.text(len)
				.toggleClass('is-ok', len >= d.min && len <= d.max)
				.toggleClass('is-warn', len > 0 && (len < d.min || len > d.max));
		};

		// Синхронізація з полями Yoast / Rank Math на тій самій сторінці,
		// щоб їхнє збереження не затерло наш опис старим значенням.
		var syncSeoPlugin = function (text) {
			try {
				if (d.seo === 'yoast') {
					$(isTerm ? '#hidden_wpseo_desc' : '#yoast_wpseo_metadesc').val(text);
					if (!isTerm && window.wp && wp.data && wp.data.dispatch('yoast-seo/editor')) {
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
				// Стор SEO-плагіна недоступний: не критично, опис уже збережено на сервері.
			}
		};

		// Актуальний (можливо ще не збережений) вміст редактора.
		var editorData = function () {
			if (isTerm) {
				return { title: $('#name').val() || '', content: editorContent('description') };
			}
			try {
				if (window.wp && wp.data && wp.data.select('core/editor')) {
					var ed = wp.data.select('core/editor');
					return { title: ed.getEditedPostAttribute('title') || '', content: ed.getEditedPostContent() || '' };
				}
			} catch (e) { /* класичний редактор (товари WooCommerce) */ }
			return { title: $('#title').val() || '', content: editorContent('content') };
		};

		var generate = function (mode) {
			$buttons.prop('disabled', true);
			$status.removeClass('is-error').text(t.generating);

			post('svtmg_generate', $.extend({ object: object, mode: mode }, editorData()))
				.done(function (res) {
					$buttons.prop('disabled', false);
					if (res && res.success) {
						$field.val(res.data.description);
						updateCounter();
						syncSeoPlugin(res.data.description);
						$box.find('.svtmg-template-badge').remove();
						$status.text(t.done);
					} else if (res && res.data && res.data.code === 'svtmg_exists') {
						if (window.confirm(t.confirmOver)) {
							generate('regenerate');
						} else {
							$status.text('');
						}
					} else {
						$status.addClass('is-error').text(t.error + ' ' + ((res && res.data && res.data.message) || ''));
					}
				})
				.fail(function (xhr) {
					$buttons.prop('disabled', false);
					$status.addClass('is-error').text(t.error + ' ' + errorText(xhr));
				});
		};

		$field.on('input', function () {
			updateCounter();
			syncSeoPlugin($field.val().trim());
		});

		$buttons.on('click', function () {
			var mode = $(this).data('svtmg-mode');
			// "Згенерувати" при заповненому полі: сервер сам вирішить (шаблонний опис замінюється без питань).
			generate(mode);
		});

		updateCounter();
	}

	/* ------------------------------------------------------------------
	 * Сторінка налаштувань
	 * ---------------------------------------------------------------- */

	$('#svtmg-test-connection').on('click', function () {
		var $btn = $(this);
		var $st = $('#svtmg-test-status');
		$btn.prop('disabled', true);
		$st.removeClass('is-error is-ok').text(t.testing);
		post('svtmg_test_connection')
			.done(function (res) {
				$st.addClass(res && res.success ? 'is-ok' : 'is-error').text((res && res.data && res.data.message) || '');
			})
			.fail(function (xhr) {
				$st.addClass('is-error').text(errorText(xhr));
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	$('#svtmg-clear-log').on('click', function () {
		if (window.confirm(t.confirmClear)) {
			post('svtmg_clear_log').done(function () {
				window.location.reload();
			});
		}
	});

	var $progress = $('#svtmg-bulk-progress');
	if ($progress.length) {
		var $fill = $progress.find('.svtmg-progress__fill');
		var $text = $progress.find('.svtmg-progress__text');
		var $start = $('#svtmg-bulk-start');
		var $cancel = $('#svtmg-bulk-cancel');
		var timer = null;

		var render = function (s) {
			var total = s.total || 0;
			var pct = total ? Math.min(100, Math.round((s.processed / total) * 100)) : 0;
			var tpl = s.status === 'done' ? t.statusDone : (s.status === 'cancelled' ? t.statusCancel : t.statusRun);

			$progress.prop('hidden', false).attr('data-status', s.status);
			$fill.css('width', pct + '%');
			$text.text(fmt(tpl, [s.processed, total, s.success, s.skipped, s.errors]) + (s.message ? '. ' + t.lastError + ' ' + s.message : ''));

			var running = s.status === 'running';
			$start.prop('disabled', running);
			$cancel.prop('disabled', !running);
			return running;
		};

		var poll = function () {
			post('svtmg_bulk_status').done(function (res) {
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
			post('svtmg_bulk_start', {
				scope: $('#svtmg-bulk-scope').val() || 'all',
				lang: $('#svtmg-bulk-lang').val() || '',
				limit: $('#svtmg-bulk-limit').val() || 0
			}).done(function (res) {
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
			post('svtmg_bulk_cancel').done(function (res) {
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
