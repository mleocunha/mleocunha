/**
 * Key Authority — chunked key generation UI.
 */
(function ($) {
	'use strict';

	var rsesPolling = null;
	var rsesBusy = false;

	function rsesCfg() {
		return window.rsesKeygen || {};
	}

	function rsesSetProgress(status) {
		var $box = $('#rses-keygen-progress');
		$box.prop('hidden', false);
		$('#rses-keygen-message').text(status.message || '');
		$('#rses-keygen-stage').text(status.stage || '');
		$('#rses-keygen-percent').text((status.progress || 0) + '%');
		$('#rses-keygen-bar-fill').css('width', (status.progress || 0) + '%');
		$('#rses-keygen-attempts').text(
			status.attempts_done
				? rsesCfg().i18n.attempts.replace('%d', status.attempts_done)
				: ''
		);
	}

	function rsesStopPolling() {
		if (rsesPolling) {
			window.clearTimeout(rsesPolling);
			rsesPolling = null;
		}
		rsesBusy = false;
		$('#rses-keygen-form :input').prop('disabled', false);
		$('#rses_keygen_submit').prop('disabled', false);
	}

	function rsesTick() {
		if (rsesBusy) {
			return;
		}
		rsesBusy = true;
		$.post(rsesCfg().ajaxUrl, {
			action: 'rses_keygen_tick',
			nonce: rsesCfg().nonce
		})
			.done(function (resp) {
				rsesBusy = false;
				if (!resp || !resp.success) {
					rsesSetProgress({
						message: (resp && resp.data && resp.data.message) || rsesCfg().i18n.error,
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
						rsesCfg().doneUrl + '&rses_key_created=' + encodeURIComponent(status.key_id || '');
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
					message: rsesCfg().i18n.error,
					progress: 0,
					stage: 'failed'
				});
				rsesStopPolling();
			});
	}

	$(document).ready(function () {
		$('#rses_key_size').on('change', function () {
			var bits = parseInt($(this).val(), 10);
			$('.rses-key-size-warning').remove();
			if (bits >= 3072) {
				$(this).after(
					'<p class="rses-key-size-warning description">' +
						rsesCfg().i18n.slowHint.replace('%d', bits) +
						'</p>'
				);
			}
		});

		$('#rses-keygen-form').on('submit', function (e) {
			e.preventDefault();
			if (!rsesCfg().ajaxUrl) {
				return;
			}

			var $form = $(this);
			var payload = $form.serializeArray();
			payload.push({ name: 'action', value: 'rses_keygen_start' });
			payload.push({ name: 'nonce', value: rsesCfg().nonce });

			$('#rses-keygen-form :input').prop('disabled', true);
			$('#rses_keygen_submit').prop('disabled', true);
			rsesSetProgress({
				message: rsesCfg().i18n.starting,
				progress: 1,
				stage: 'safe_prime',
				attempts_done: 0
			});

			$.post(rsesCfg().ajaxUrl, payload)
				.done(function (resp) {
					if (!resp || !resp.success) {
						rsesSetProgress({
							message:
								(resp && resp.data && resp.data.message) || rsesCfg().i18n.error,
							progress: 0,
							stage: 'failed'
						});
						rsesStopPolling();
						return;
					}
					rsesSetProgress(resp.data);
					if (resp.data.stage === 'complete') {
						window.location =
							rsesCfg().doneUrl +
							'&rses_key_created=' +
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
					var msg = rsesCfg().i18n.error;
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						msg = xhr.responseJSON.data.message;
					}
					rsesSetProgress({ message: msg, progress: 0, stage: 'failed' });
					rsesStopPolling();
				});
		});

		$('#rses-keygen-cancel').on('click', function () {
			$.post(rsesCfg().ajaxUrl, {
				action: 'rses_keygen_cancel',
				nonce: rsesCfg().nonce
			}).always(function (resp) {
				var status =
					resp && resp.success && resp.data
						? resp.data
						: { message: rsesCfg().i18n.cancelled, progress: 0, stage: 'cancelled' };
				rsesSetProgress(status);
				rsesStopPolling();
			});
		});

		// Resume UI if a job is already active.
		if (rsesCfg().ajaxUrl) {
			$.post(rsesCfg().ajaxUrl, {
				action: 'rses_keygen_status',
				nonce: rsesCfg().nonce
			}).done(function (resp) {
				if (resp && resp.success && resp.data && resp.data.active) {
					$('#rses-keygen-form :input').prop('disabled', true);
					rsesSetProgress(resp.data);
					rsesPolling = window.setTimeout(rsesTick, 300);
				}
			});
		}
	});
})(jQuery);
