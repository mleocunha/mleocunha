/**
 * Electoral roll — chunked upload + batched AJAX import.
 */
(function ($) {
	'use strict';

	var rsesBusy = false;
	var rsesCancelled = false;

	function rsesCfg() {
		var cfg = window.rsesElectoralRoll || {};
		var $form = $('#rses-electoral-form');
		if ($form.length) {
			cfg.ajaxUrl = cfg.ajaxUrl || $form.data('rsesAjaxUrl') || '';
			cfg.nonce = cfg.nonce || $form.data('rsesNonce') || '';
			cfg.maxBytes = cfg.maxBytes || parseInt($form.data('rsesMaxBytes'), 10) || 0;
			cfg.chunkBytes = cfg.chunkBytes || parseInt($form.data('rsesChunkBytes'), 10) || 262144;
		}
		cfg.i18n = cfg.i18n || {};
		return cfg;
	}

	function rsesPost(action, data) {
		var cfg = rsesCfg();
		data = data || {};
		data.action = action;
		data.nonce = cfg.nonce;
		return $.ajax({
			url: cfg.ajaxUrl,
			method: 'POST',
			data: data,
			dataType: 'json'
		});
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
			contentType: false,
			dataType: 'json'
		});
	}

	function rsesSetProgress(status) {
		status = status || {};
		var $box = $('#rses-electoral-progress');
		$box.removeAttr('hidden').removeClass('is-idle').addClass('is-active').show();
		$('#rses-electoral-message').text(status.message || '');
		$('#rses-electoral-stage').text(status.stage || '');
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

	function rsesFail(message) {
		var cfg = rsesCfg();
		rsesSetProgress({
			message: message || (cfg.i18n && cfg.i18n.error) || 'Electoral roll import failed.',
			progress: 0,
			stage: 'failed'
		});
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
					if (st) {
						rsesSetProgress(st);
						rsesRenderErrors(st);
					}
					rsesFail(
						(resp && resp.data && resp.data.message) ||
							(rsesCfg().i18n && rsesCfg().i18n.error) ||
							'Electoral roll import failed.'
					);
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
			.fail(function () {
				rsesFail((rsesCfg().i18n && rsesCfg().i18n.error) || 'Electoral roll import failed.');
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
			fd.append('chunk', blob, file.name + '.part' + index);

			return rsesPostFormData('rses_electoral_roll_chunk', fd).then(function (resp) {
				if (!resp || !resp.success) {
					return $.Deferred()
						.reject({
							message:
								(resp && resp.data && resp.data.message) ||
								(rsesCfg().i18n && rsesCfg().i18n.error) ||
								'Upload failed.'
						})
						.promise();
				}
				rsesSetProgress(resp.data || {});
				index += 1;
				return next();
			});
		}

		return next();
	}

	function rsesStartImport(e) {
		if (e) {
			e.preventDefault();
			e.stopPropagation();
		}

		var cfg = rsesCfg();
		var input = document.getElementById('rses_electoral_roll_csv');
		var file = input && input.files && input.files[0];

		if (!file) {
			rsesFail((cfg.i18n && cfg.i18n.noFile) || 'Choose a CSV file first.');
			return;
		}
		if (cfg.maxBytes && file.size > cfg.maxBytes) {
			rsesFail((cfg.i18n && cfg.i18n.tooLarge) || 'CSV file is too large.');
			return;
		}
		if (!cfg.ajaxUrl || !cfg.nonce) {
			rsesFail((cfg.i18n && cfg.i18n.noJs) || 'JavaScript failed to start the import.');
			return;
		}

		rsesCancelled = false;
		$('#rses-electoral-result').attr('hidden', true).hide();
		$('#rses-electoral-errors-live').attr('hidden', true).hide();
		rsesSetFormBusy(true);

		var chunkBytes = cfg.chunkBytes || 262144;
		var totalChunks = Math.max(1, Math.ceil(file.size / chunkBytes));

		rsesSetProgress({
			message: (cfg.i18n && cfg.i18n.starting) || 'Starting chunked import…',
			progress: 1,
			stage: 'receiving',
			created: 0,
			updated: 0,
			skipped: 0,
			error_count: 0
		});

		rsesPost('rses_electoral_roll_init', {
			filename: file.name,
			total_chunks: totalChunks,
			total_bytes: file.size,
			update_existing: $('#rses_update_existing').is(':checked') ? 1 : 0
		})
			.then(function (resp) {
				if (!resp || !resp.success) {
					return $.Deferred()
						.reject({
							message:
								(resp && resp.data && resp.data.message) ||
								(cfg.i18n && cfg.i18n.error) ||
								'Could not start import.'
						})
						.promise();
				}
				rsesSetProgress(resp.data || {});
				return rsesUploadChunks(file, totalChunks, chunkBytes);
			})
			.then(function () {
				if (rsesCancelled) {
					return;
				}
				rsesSetProgress({
					message: (cfg.i18n && cfg.i18n.validating) || 'Validating CSV…',
					progress: 22,
					stage: 'ready'
				});
				return rsesPost('rses_electoral_roll_begin');
			})
			.then(function (resp) {
				if (rsesCancelled || resp === undefined) {
					return;
				}
				if (!resp || !resp.success) {
					var st = resp && resp.data && resp.data.status;
					if (st) {
						rsesSetProgress(st);
						rsesRenderErrors(st);
					}
					return $.Deferred()
						.reject({
							message:
								(resp && resp.data && resp.data.message) ||
								(cfg.i18n && cfg.i18n.error) ||
								'CSV validation failed.'
						})
						.promise();
				}
				rsesSetProgress(resp.data || {});
				rsesTickLoop();
			})
			.fail(function (err) {
				if (err && err.cancelled) {
					return;
				}
				rsesFail((err && err.message) || (cfg.i18n && cfg.i18n.error) || 'Electoral roll import failed.');
			});
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

		// Resume an in-flight job after refresh.
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
