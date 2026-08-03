/**
 * Electoral roll — upload + batched AJAX import.
 */
(function ($) {
	'use strict';

	var rsesBusy = false;
	var rsesCancelled = false;
	/**
	 * Prefer single POST only for tiny files. Typical electoral rolls (~1–2 MiB)
	 * always use chunked upload so PHP post_max_size cannot empty $_POST/$_FILES.
	 */
	var RSES_SINGLE_UPLOAD_CAP = 512 * 1024;

	function rsesCfg() {
		var cfg = window.rsesElectoralRoll || {};
		var $form = $('#rses-electoral-form');
		if ($form.length) {
			cfg.ajaxUrl = cfg.ajaxUrl || $form.data('rsesAjaxUrl') || '';
			cfg.nonce = cfg.nonce || $form.data('rsesNonce') || '';
			cfg.maxBytes = cfg.maxBytes || parseInt($form.data('rsesMaxBytes'), 10) || 0;
			cfg.chunkBytes = cfg.chunkBytes || parseInt($form.data('rsesChunkBytes'), 10) || 131072;
			cfg.phpUploadMax =
				cfg.phpUploadMax || parseInt($form.data('rsesPhpUploadMax'), 10) || 0;
		}
		cfg.i18n = cfg.i18n || {};
		return cfg;
	}

	function rsesFallbackError() {
		var cfg = rsesCfg();
		return (cfg.i18n && cfg.i18n.error) || 'Electoral roll import failed.';
	}

	function rsesPickMessage(obj) {
		if (!obj) {
			return '';
		}
		if (typeof obj === 'string' && obj) {
			return obj;
		}
		if (typeof obj.message === 'string' && obj.message) {
			return obj.message;
		}
		if (obj.data) {
			if (typeof obj.data === 'string' && obj.data) {
				return obj.data;
			}
			if (obj.data && typeof obj.data.message === 'string' && obj.data.message) {
				return obj.data.message;
			}
		}
		return '';
	}

	/**
	 * Extract a human message from jqXHR / Deferred reject payloads.
	 */
	function rsesErrorMessage(err, fallback) {
		fallback = fallback || rsesFallbackError();
		if (!err) {
			return fallback;
		}
		if (typeof err === 'string' && err) {
			return err;
		}

		var picked = rsesPickMessage(err);
		if (picked) {
			return picked;
		}

		var json = err.responseJSON;
		picked = rsesPickMessage(json) || rsesPickMessage(json && json.data);
		if (picked) {
			return picked;
		}

		if (err.responseText && typeof err.responseText === 'string') {
			var text = err.responseText.replace(/^\uFEFF/, '').trim();
			if (text === '-1' || text === '0' || text === '-1\n' || text === '0\n') {
				return (
					fallback +
					' — ' +
					'Security check failed. Hard-refresh this page and try again.'
				);
			}
			try {
				var parsed = JSON.parse(text);
				picked = rsesPickMessage(parsed) || rsesPickMessage(parsed && parsed.data);
				if (picked) {
					return picked;
				}
			} catch (e) {
				/* not JSON */
			}
			if (/permission|nonce|forbidden|not available|security check/i.test(text)) {
				return fallback + ' (HTTP ' + (err.status || '?') + ')';
			}
			// Surface a short non-HTML preview so fatals/WAF pages are diagnosable.
			var plain = text
				.replace(/<script[\s\S]*?<\/script>/gi, ' ')
				.replace(/<style[\s\S]*?<\/style>/gi, ' ')
				.replace(/<[^>]+>/g, ' ')
				.replace(/\s+/g, ' ')
				.trim();
			if (plain && plain.length > 8 && plain.length < 280) {
				return fallback + ' — ' + plain;
			}
			if (plain && plain.length >= 280) {
				return fallback + ' — ' + plain.slice(0, 240) + '…';
			}
		}

		if (err.statusText === 'parsererror') {
			return fallback + ' (invalid JSON from server — hard-refresh and retry)';
		}
		if (err.status) {
			return fallback + ' (HTTP ' + err.status + ')';
		}
		return fallback;
	}

	/**
	 * Parse admin-ajax responses without forcing jQuery dataType:json
	 * (notices/HTML before JSON used to become opaque parsererrors).
	 */
	function rsesParseAjax(payload, xhr) {
		if (payload && typeof payload === 'object') {
			return payload;
		}
		var text =
			(xhr && xhr.responseText) ||
			(typeof payload === 'string' ? payload : '') ||
			'';
		text = String(text).replace(/^\uFEFF/, '').trim();
		if (!text) {
			return null;
		}
		// Strip leading PHP notices if a JSON object still follows.
		var brace = text.indexOf('{');
		if (brace > 0) {
			text = text.slice(brace);
		}
		try {
			return JSON.parse(text);
		} catch (e) {
			return null;
		}
	}

	function rsesPost(action, data) {
		var cfg = rsesCfg();
		data = data || {};
		data.action = action;
		data.nonce = cfg.nonce;
		return $.ajax({
			url: cfg.ajaxUrl,
			method: 'POST',
			data: data
		}).then(
			function (payload, _text, xhr) {
				var resp = rsesParseAjax(payload, xhr);
				if (!resp) {
					return $.Deferred()
						.reject({
							message: rsesErrorMessage(xhr, rsesFallbackError()),
							status: null,
							xhr: xhr
						})
						.promise();
				}
				return resp;
			},
			function (xhr) {
				return $.Deferred()
					.reject({
						message: rsesErrorMessage(xhr, rsesFallbackError()),
						status:
							xhr &&
							xhr.responseJSON &&
							xhr.responseJSON.data &&
							xhr.responseJSON.data.status,
						xhr: xhr
					})
					.promise();
			}
		);
	}

	function rsesPostFormData(action, formData) {
		var cfg = rsesCfg();
		formData.append('action', action);
		formData.append('nonce', cfg.nonce);
		return $.ajax({
			url: cfg.ajaxUrl,
			method: 'POST',
			data: formData,
			processData: false,
			contentType: false
		}).then(
			function (payload, _text, xhr) {
				var resp = rsesParseAjax(payload, xhr);
				if (!resp) {
					return $.Deferred()
						.reject({
							message: rsesErrorMessage(xhr, rsesFallbackError()),
							status: null,
							xhr: xhr
						})
						.promise();
				}
				return resp;
			},
			function (xhr) {
				return $.Deferred()
					.reject({
						message: rsesErrorMessage(xhr, rsesFallbackError()),
						status:
							xhr &&
							xhr.responseJSON &&
							xhr.responseJSON.data &&
							xhr.responseJSON.data.status,
						xhr: xhr
					})
					.promise();
			}
		);
	}

	function rsesStageLabel(status) {
		var cfg = rsesCfg();
		var key = (status && status.stage) || '';
		if (status && status.stage_label) {
			return status.stage_label;
		}
		if (cfg.i18n && cfg.i18n.stages && cfg.i18n.stages[key]) {
			return cfg.i18n.stages[key];
		}
		return '';
	}

	function rsesIsJobStatus(status) {
		return !!(
			status &&
			typeof status === 'object' &&
			!Array.isArray(status) &&
			(Object.prototype.hasOwnProperty.call(status, 'stage') ||
				Object.prototype.hasOwnProperty.call(status, 'progress') ||
				Object.prototype.hasOwnProperty.call(status, 'error_count'))
		);
	}

	function rsesSetProgress(status) {
		status = status || {};
		var $box = $('#rses-electoral-progress');
		$box.removeAttr('hidden').removeClass('is-idle').addClass('is-active').show();
		$('#rses-electoral-message').text(status.message || '');
		$('#rses-electoral-stage').text(rsesStageLabel(status));
		$('#rses-electoral-percent').text((status.progress || 0) + '%');
		$('#rses-electoral-bar-fill').css('width', (status.progress || 0) + '%');
		$('#rses-electoral-created').text(status.created || 0);
		$('#rses-electoral-updated').text(status.updated || 0);
		$('#rses-electoral-skipped').text(status.skipped || 0);
		$('#rses-electoral-error-count').text(status.error_count || 0);
	}

	function rsesSetFormBusy(busy) {
		rsesBusy = !!busy;
		$('#rses-electoral-form').attr('aria-busy', busy ? 'true' : 'false');
		$('#rses-electoral-form :input').prop('disabled', !!busy);
		$('#rses-electoral-cancel').prop('disabled', !busy);
		$('#rses-electoral-submit').prop('disabled', !!busy);
		$('#rses_electoral_roll_csv').prop('disabled', !!busy);
	}

	function rsesShowResult(status) {
		var cfg = rsesCfg();
		var tpl =
			(cfg.i18n && cfg.i18n.finished) ||
			'Electoral roll import finished. Created: %1$d. Updated: %2$d. Skipped: %3$d. Errors: %4$d.';
		var text = tpl
			.replace('%1$d', status.created || 0)
			.replace('%2$d', status.updated || 0)
			.replace('%3$d', status.skipped || 0)
			.replace('%4$d', status.error_count || 0);

		var $wrap = $('#rses-electoral-result').removeAttr('hidden').show();
		var $panel = $wrap.find('.rses-panel').first();
		$panel
			.removeClass('rses-panel-success rses-panel-warning')
			.addClass((status.error_count || 0) > 0 ? 'rses-panel-warning' : 'rses-panel-success');
		$('#rses-electoral-result-text').text(text);

		rsesRenderErrors(status);
	}

	function rsesRenderErrors(status) {
		var errors = status.errors || [];
		var count = status.error_count || errors.length || 0;
		var $live = $('#rses-electoral-errors-live');
		var $body = $('#rses-electoral-errors-body');
		var cfg = rsesCfg();

		if (!count) {
			$live.attr('hidden', true).hide();
			$body.empty();
			return;
		}

		var descTpl =
			(cfg.i18n && cfg.i18n.errorsDesc) ||
			'%d issue(s) were reported. Review the table below or download the error CSV.';
		$('#rses-electoral-errors-desc').text(descTpl.replace('%d', count));
		$body.empty();
		errors.forEach(function (msg, i) {
			var $tr = $('<tr/>');
			$tr.append($('<td/>').text(String(i + 1)));
			$tr.append($('<td/>').text(String(msg)));
			$body.append($tr);
		});
		if (count > errors.length) {
			var more =
				(cfg.i18n && cfg.i18n.errorsTruncated) ||
				'Additional errors were omitted from this preview; download the CSV for the stored sample.';
			var $trMore = $('<tr/>');
			$trMore.append($('<td/>').text('…'));
			$trMore.append($('<td/>').text(more));
			$body.append($trMore);
		}
		$live.removeAttr('hidden').show();
	}

	function rsesFail(message, status) {
		var cfg = rsesCfg();
		var jobStatus = rsesIsJobStatus(status) ? status : null;
		var failedLabel =
			(cfg.i18n && cfg.i18n.stages && cfg.i18n.stages.failed) || '';
		var msg = message || (jobStatus && jobStatus.message) || rsesFallbackError();
		var currentProgress = parseInt($('#rses-electoral-percent').text(), 10) || 0;

		if (jobStatus) {
			var progress =
				typeof jobStatus.progress === 'number' && jobStatus.progress > 0
					? jobStatus.progress
					: Math.max(currentProgress, 0);
			rsesSetProgress(
				$.extend({}, jobStatus, {
					message: msg,
					stage: jobStatus.stage || 'failed',
					stage_label: jobStatus.stage_label || failedLabel,
					progress: progress
				})
			);
			rsesRenderErrors(jobStatus);
		} else {
			rsesSetProgress({
				message: msg,
				progress: currentProgress > 0 ? currentProgress : 0,
				stage: 'failed',
				stage_label: failedLabel
			});
		}
		rsesSetFormBusy(false);
	}

	function rsesTickLoop() {
		if (rsesCancelled) {
			return;
		}
		rsesPost('rses_electoral_roll_tick')
			.done(function (resp) {
				if (!resp || !resp.success) {
					var st = resp && resp.data && resp.data.status;
					rsesFail(rsesErrorMessage(resp && resp.data, rsesFallbackError()), st);
					return;
				}
				var status = resp.data || {};
				rsesSetProgress(status);
				if (status.stage === 'complete') {
					rsesShowResult(status);
					rsesSetFormBusy(false);
					return;
				}
				if (status.stage === 'failed' || status.stage === 'cancelled') {
					rsesRenderErrors(status);
					rsesSetFormBusy(false);
					return;
				}
				window.setTimeout(rsesTickLoop, 40);
			})
			.fail(function (err) {
				rsesFail(rsesErrorMessage(err), err && err.status);
			});
	}

	function rsesUploadChunks(file, totalChunks, chunkBytes) {
		var index = 0;

		function next() {
			if (rsesCancelled) {
				return $.Deferred().reject({ cancelled: true }).promise();
			}
			if (index >= totalChunks) {
				return $.Deferred().resolve().promise();
			}
			var start = index * chunkBytes;
			var blob = file.slice(start, Math.min(file.size, start + chunkBytes));
			var fd = new FormData();
			fd.append('chunk_index', String(index));
			fd.append('chunk', blob, 'part-' + index + '.bin');

			return rsesPostFormData('rses_electoral_roll_chunk', fd).then(
				function (resp) {
					if (!resp || !resp.success) {
						return $.Deferred()
							.reject({
								message: rsesErrorMessage(resp && resp.data, rsesFallbackError()),
								status: resp && resp.data && resp.data.status
							})
							.promise();
					}
					rsesSetProgress(resp.data || {});
					index += 1;
					return next();
				},
				function (err) {
					return $.Deferred()
						.reject({
							message: rsesErrorMessage(err),
							status: err && err.status
						})
						.promise();
				}
			);
		}

		return next();
	}

	function rsesAfterReady(status) {
		rsesSetProgress(status || {});
		if (status && status.stage === 'importing') {
			rsesTickLoop();
			return;
		}
		if (status && status.stage === 'complete') {
			rsesShowResult(status);
			rsesSetFormBusy(false);
			return;
		}
		rsesFail(
			(status && status.message) || rsesFallbackError(),
			status
		);
	}

	function rsesSafeSingleLimit() {
		var cfg = rsesCfg();
		var phpMax = parseInt(cfg.phpUploadMax, 10) || 0;
		// Keep headroom for multipart boundaries + nonce fields.
		var fromPhp = phpMax > 0 ? Math.floor(phpMax * 0.7) : RSES_SINGLE_UPLOAD_CAP;
		return Math.max(32 * 1024, Math.min(RSES_SINGLE_UPLOAD_CAP, fromPhp));
	}

	function rsesStartSingleUpload(file, updateExisting) {
		var cfg = rsesCfg();
		var fd = new FormData();
		fd.append('csv', file, file.name || 'cadastro.csv');
		fd.append('update_existing', updateExisting ? '1' : '0');

		rsesSetProgress({
			message: (cfg.i18n && cfg.i18n.starting) || 'Starting chunked import…',
			progress: 5,
			stage: 'receiving',
			stage_label: cfg.i18n && cfg.i18n.stages ? cfg.i18n.stages.receiving : '',
			created: 0,
			updated: 0,
			skipped: 0,
			error_count: 0
		});

		rsesPostFormData('rses_electoral_roll_upload', fd)
			.done(function (resp) {
				if (!resp || !resp.success) {
					var st = resp && resp.data && resp.data.status;
					rsesFail(rsesErrorMessage(resp && resp.data, rsesFallbackError()), st);
					return;
				}
				rsesAfterReady(resp.data || {});
			})
			.fail(function (err) {
				// Fall back to chunked upload when a single POST is rejected by PHP limits.
				var msg = rsesErrorMessage(err);
				if (/post_max_size|upload limit|partially received|No CSV file/i.test(msg)) {
					rsesStartChunkedUpload(file, updateExisting);
					return;
				}
				rsesFail(msg, err && err.status);
			});
	}

	function rsesStartChunkedUpload(file, updateExisting) {
		var cfg = rsesCfg();
		var chunkBytes = cfg.chunkBytes || 131072;
		var totalChunks = Math.max(1, Math.ceil(file.size / chunkBytes));

		rsesSetProgress({
			message: (cfg.i18n && cfg.i18n.starting) || 'Starting chunked import…',
			progress: 1,
			stage: 'receiving',
			stage_label: cfg.i18n && cfg.i18n.stages ? cfg.i18n.stages.receiving : '',
			created: 0,
			updated: 0,
			skipped: 0,
			error_count: 0
		});

		rsesPost('rses_electoral_roll_init', {
			filename: file.name,
			total_chunks: totalChunks,
			total_bytes: file.size,
			update_existing: updateExisting ? 1 : 0
		})
			.then(
				function (resp) {
					if (!resp || !resp.success) {
						return $.Deferred()
							.reject({
								message: rsesErrorMessage(resp && resp.data, rsesFallbackError()),
								status: resp && resp.data && resp.data.status
							})
							.promise();
					}
					rsesSetProgress(resp.data || {});
					return rsesUploadChunks(file, totalChunks, chunkBytes);
				},
				function (err) {
					return $.Deferred()
						.reject({
							message: rsesErrorMessage(err),
							status: err && err.status
						})
						.promise();
				}
			)
			.then(function () {
				if (rsesCancelled) {
					return;
				}
				rsesSetProgress({
					message: (cfg.i18n && cfg.i18n.validating) || 'Validating CSV…',
					progress: 22,
					stage: 'ready',
					stage_label: cfg.i18n && cfg.i18n.stages ? cfg.i18n.stages.ready : ''
				});
				return rsesPost('rses_electoral_roll_begin');
			})
			.then(function (resp) {
				if (rsesCancelled || resp === undefined) {
					return;
				}
				if (!resp || !resp.success) {
					var st = resp && resp.data && resp.data.status;
					var msg =
						rsesErrorMessage(resp && resp.data, '') ||
						(st && st.message) ||
						rsesFallbackError();
					return $.Deferred()
						.reject({
							message: msg,
							status: st
						})
						.promise();
				}
				rsesAfterReady(resp.data || {});
			})
			.fail(function (err) {
				if (err && err.cancelled) {
					return;
				}
				var st = err && rsesIsJobStatus(err.status) ? err.status : null;
				var msg =
					rsesErrorMessage(err, '') ||
					(st && st.message) ||
					rsesFallbackError();
				rsesFail(msg, st);
			});
	}

	function rsesStartImport(e) {
		if (e) {
			e.preventDefault();
			e.stopPropagation();
		}

		// Synchronous guard — must run before any await/XHR so a double-click
		// cannot start two chunked uploads that append the same CSV twice.
		if (rsesBusy) {
			return;
		}
		rsesBusy = true;

		var cfg = rsesCfg();
		var input = document.getElementById('rses_electoral_roll_csv');
		var file = input && input.files && input.files[0];
		var updateExisting = $('#rses_update_existing').is(':checked');

		if (!file) {
			rsesBusy = false;
			rsesFail((cfg.i18n && cfg.i18n.noFile) || 'Choose a CSV file first.');
			return;
		}
		if (cfg.maxBytes && file.size > cfg.maxBytes) {
			rsesBusy = false;
			rsesFail((cfg.i18n && cfg.i18n.tooLarge) || 'CSV file is too large.');
			return;
		}
		if (!cfg.ajaxUrl || !cfg.nonce) {
			rsesBusy = false;
			rsesFail((cfg.i18n && cfg.i18n.noJs) || 'JavaScript failed to start the import.');
			return;
		}

		rsesCancelled = false;
		$('#rses-electoral-result').attr('hidden', true).hide();
		$('#rses-electoral-errors-live').attr('hidden', true).hide();
		rsesSetFormBusy(true);

		// Default to chunked for typical electoral rolls so PHP post_max_size
		// cannot wipe $_POST/$_FILES and produce a generic failure.
		if (file.size <= rsesSafeSingleLimit()) {
			rsesStartSingleUpload(file, updateExisting);
		} else {
			rsesStartChunkedUpload(file, updateExisting);
		}
	}

	function rsesCancel() {
		var cfg = rsesCfg();
		rsesCancelled = true;
		rsesPost('rses_electoral_roll_cancel')
			.always(function (resp) {
				var status =
					resp && resp.success && resp.data
						? resp.data
						: {
								message: (cfg.i18n && cfg.i18n.cancelled) || 'Electoral roll import cancelled.',
								progress: 0,
								stage: 'cancelled'
							};
				rsesSetProgress(status);
				rsesSetFormBusy(false);
			});
	}

	function rsesBindDropzone() {
		var $zone = $('#rses-electoral-dropzone');
		var $input = $('#rses_electoral_roll_csv');
		var $label = $('#rses-electoral-file-label');
		var idle = $label.text();

		function showName() {
			var f = $input[0] && $input[0].files && $input[0].files[0];
			$label.text(f ? f.name : idle);
			$zone.toggleClass('has-file', !!f);
		}

		$input.on('change', showName);

		$zone.on('dragenter dragover', function (e) {
			e.preventDefault();
			e.stopPropagation();
			$zone.addClass('is-dragover');
		});
		$zone.on('dragleave drop', function (e) {
			e.preventDefault();
			e.stopPropagation();
			$zone.removeClass('is-dragover');
		});
		$zone.on('drop', function (e) {
			var dt = e.originalEvent && e.originalEvent.dataTransfer;
			if (!dt || !dt.files || !dt.files.length) {
				return;
			}
			try {
				if (typeof DataTransfer !== 'undefined') {
					var transfer = new DataTransfer();
					transfer.items.add(dt.files[0]);
					$input[0].files = transfer.files;
				} else {
					$input[0].files = dt.files;
				}
			} catch (err) {
				$input[0].files = dt.files;
			}
			showName();
		});
	}

	$(function () {
		if (!$('#rses-electoral-form').length) {
			return;
		}
		rsesBindDropzone();
		$('#rses-electoral-form').on('submit', rsesStartImport);
		$('#rses-electoral-cancel').on('click', rsesCancel);
		$('#rses-electoral-cancel').prop('disabled', true);

		var cfg = rsesCfg();
		if (cfg.resume && cfg.resume.active) {
			rsesSetProgress(cfg.resume);
			rsesSetFormBusy(true);
			if (cfg.resume.stage === 'importing') {
				rsesTickLoop();
			}
		}
	});
})(jQuery);
