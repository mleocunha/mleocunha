/**
 * Key Authority — chunked key generation UI.
 */
(function ($) {
	'use strict';

	var rsesPolling = null;
	var rsesBusy = false;

	function rsesCfg() {
		var cfg = window.rsesKeygen || {};
		var $form = $('#rses-keygen-form');
		if ($form.length) {
			cfg.ajaxUrl = cfg.ajaxUrl || $form.data('rsesAjaxUrl') || '';
			cfg.nonce = cfg.nonce || $form.data('rsesNonce') || '';
			cfg.doneUrl = cfg.doneUrl || $form.data('rsesDoneUrl') || '';
		}
		cfg.i18n = cfg.i18n || {};
		return cfg;
	}

	function rsesSetProgress(status) {
		var $box = $('#rses-keygen-progress');
		$box.removeAttr('hidden').removeClass('is-idle').addClass('is-active').show();
		$('#rses-keygen-message').text(status.message || '');
		$('#rses-keygen-stage').text(status.stage || '');
		$('#rses-keygen-percent').text((status.progress || 0) + '%');
		$('#rses-keygen-bar-fill').css('width', (status.progress || 0) + '%');
		var attemptsTpl = (rsesCfg().i18n && rsesCfg().i18n.attempts) || '%d candidates tested';
		$('#rses-keygen-attempts').text(
			status.attempts_done ? attemptsTpl.replace('%d', status.attempts_done) : ''
		);
	}

	function rsesStopPolling() {
		if (rsesPolling) {
			window.clearTimeout(rsesPolling);
			rsesPolling = null;
		}
		rsesBusy = false;
		$('#rses-keygen-form').attr('aria-busy', 'false');
		$('#rses-keygen-form :input').prop('disabled', false);
		$('#rses_keygen_submit').prop('disabled', false);
	}

	function rsesTick() {
		var cfg = rsesCfg();
		if (rsesBusy || !cfg.ajaxUrl) {
			return;
		}
		rsesBusy = true;
		$.post(cfg.ajaxUrl, {
			action: 'rses_keygen_tick',
			nonce: cfg.nonce
		})
			.done(function (resp) {
				rsesBusy = false;
				if (!resp || !resp.success) {
					rsesSetProgress({
						message:
							(resp && resp.data && resp.data.message) ||
							(cfg.i18n && cfg.i18n.error) ||
							'Key generation request failed.',
						progress: 0,
						stage: 'failed'
					});
					rsesStopPolling();
					return;
				}
				var status = resp.data;
				rsesSetProgress(status);
				if (status.stage === 'complete') {
					rsesStopPolling();
					window.location =
						cfg.doneUrl +
						(cfg.doneUrl.indexOf('?') >= 0 ? '&' : '?') +
						'rses_key_created=' +
						encodeURIComponent(status.key_id || '');
					return;
				}
				if (status.stage === 'failed' || status.stage === 'cancelled') {
					rsesStopPolling();
					return;
				}
				if (status.active) {
					rsesPolling = window.setTimeout(rsesTick, 300);
				} else {
					rsesStopPolling();
				}
			})
			.fail(function () {
				rsesBusy = false;
				rsesSetProgress({
					message: (cfg.i18n && cfg.i18n.error) || 'Key generation request failed.',
					progress: 0,
					stage: 'failed'
				});
				rsesStopPolling();
			});
	}

	function rsesStartKeygen(e) {
		if (e) {
			e.preventDefault();
			e.stopPropagation();
		}

		var cfg = rsesCfg();
		var $form = $('#rses-keygen-form');

		// Show the bar immediately so a silent JS/AJAX failure is never invisible.
		rsesSetProgress({
			message: (cfg.i18n && cfg.i18n.starting) || 'Starting chunked key generation…',
			progress: 1,
			stage: 'safe_prime',
			attempts_done: 0
		});

		if (!cfg.ajaxUrl || !cfg.nonce) {
			rsesSetProgress({
				message:
					(cfg.i18n && cfg.i18n.noJs) ||
					'JavaScript failed to start key generation. Hard-refresh this page and try again.',
				progress: 0,
				stage: 'failed'
			});
			return false;
		}

		// Serialize before disabling inputs.
		var payload = $form.serializeArray().filter(function (field) {
			return field.name !== 'action';
		});
		payload.push({ name: 'action', value: 'rses_keygen_start' });
		payload.push({ name: 'nonce', value: cfg.nonce });

		$form.attr('aria-busy', 'true');
		$form.find(':input').prop('disabled', true);
		$('#rses_keygen_submit').prop('disabled', true);

		$.post(cfg.ajaxUrl, $.param(payload))
			.done(function (resp) {
				if (!resp || !resp.success) {
					rsesSetProgress({
						message:
							(resp && resp.data && resp.data.message) ||
							(cfg.i18n && cfg.i18n.error) ||
							'Key generation request failed.',
						progress: 0,
						stage: 'failed'
					});
					rsesStopPolling();
					return;
				}
				rsesSetProgress(resp.data);
				if (resp.data.stage === 'complete') {
					window.location =
						cfg.doneUrl +
						(cfg.doneUrl.indexOf('?') >= 0 ? '&' : '?') +
						'rses_key_created=' +
						encodeURIComponent(resp.data.key_id || '');
					return;
				}
				if (resp.data.active) {
					rsesPolling = window.setTimeout(rsesTick, 300);
				} else {
					rsesStopPolling();
				}
			})
			.fail(function (xhr) {
				var msg = (cfg.i18n && cfg.i18n.error) || 'Key generation request failed.';
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				rsesSetProgress({ message: msg, progress: 0, stage: 'failed' });
				rsesStopPolling();
			});

		return false;
	}

	$(function () {
		var $form = $('#rses-keygen-form');
		if (!$form.length) {
			return;
		}

		$('#rses_key_size').on('change', function () {
			var bits = parseInt($(this).val(), 10);
			$('.rses-key-size-warning').remove();
			if (bits >= 3072) {
				var hint =
					(rsesCfg().i18n && rsesCfg().i18n.slowHint) ||
					'Key generation at %d bits uses chunked AJAX and may take several minutes.';
				$(this).after(
					'<p class="rses-key-size-warning description">' +
						hint.replace('%d', bits) +
						'</p>'
				);
			}
		});

		$form.on('submit', rsesStartKeygen);
		$(document).on('click', '#rses_keygen_submit', function (e) {
			// Some admin skins intercept submit; handle the button click explicitly.
			if ($form[0] && typeof $form[0].reportValidity === 'function' && !$form[0].reportValidity()) {
				return;
			}
			rsesStartKeygen(e);
		});

		$('#rses-keygen-cancel').on('click', function () {
			var cfg = rsesCfg();
			if (!cfg.ajaxUrl) {
				rsesStopPolling();
				return;
			}
			$.post(cfg.ajaxUrl, {
				action: 'rses_keygen_cancel',
				nonce: cfg.nonce
			}).always(function (resp) {
				var status =
					resp && resp.success && resp.data
						? resp.data
						: {
								message:
									(cfg.i18n && cfg.i18n.cancelled) || 'Key generation cancelled.',
								progress: 0,
								stage: 'cancelled'
						  };
				rsesSetProgress(status);
				rsesStopPolling();
			});
		});

		// Resume UI if a job is already active.
		var cfg = rsesCfg();
		if (cfg.ajaxUrl && cfg.nonce) {
			$.post(cfg.ajaxUrl, {
				action: 'rses_keygen_status',
				nonce: cfg.nonce
			}).done(function (resp) {
				if (resp && resp.success && resp.data && resp.data.active) {
					$form.find(':input').prop('disabled', true);
					rsesSetProgress(resp.data);
					rsesPolling = window.setTimeout(rsesTick, 300);
				}
			});
		}
	});
})(jQuery);
