/**
 * RelataSoft Secure Election Suite - Admin JS
 */
(function ($) {
	'use strict';

	function rsesCopyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}

		var $temp = $('<textarea>');
		$('body').append($temp);
		$temp.val(text).trigger('select');
		document.execCommand('copy');
		$temp.remove();
		return Promise.resolve();
	}

	function rsesI18n(key, fallback) {
		var cfg = window.rsesAdmin && window.rsesAdmin.i18n ? window.rsesAdmin.i18n : {};
		return cfg[key] || fallback;
	}

	function rsesMediaKind(mime) {
		mime = (mime || '').toLowerCase();
		if (mime.indexOf('image/') === 0) {
			return 'image';
		}
		if (mime.indexOf('audio/') === 0) {
			return 'audio';
		}
		if (mime.indexOf('video/') === 0) {
			return 'video';
		}
		return '';
	}

	function rsesSetOptionMedia($row, attachment) {
		var $hidden = $row.find('.rses-option-attachment-id');
		var $preview = $row.find('.rses-option-media-preview');
		var $clear = $row.find('.rses-option-media-clear');

		if (!attachment || !attachment.id) {
			$hidden.val('');
			$preview.attr('hidden', true).empty();
			$clear.attr('hidden', true);
			return;
		}

		var kind = rsesMediaKind(attachment.mime || (attachment.attributes && attachment.attributes.mime));
		var url =
			attachment.url ||
			(attachment.attributes && attachment.attributes.url) ||
			'';
		var thumb =
			(attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) ||
			(attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) ||
			url;

		$hidden.val(String(attachment.id));
		$clear.removeAttr('hidden');
		$preview.removeAttr('hidden').empty();

		if (kind === 'image' && thumb) {
			$preview.append(
				$('<img>', {
					src: thumb,
					alt: attachment.filename || rsesI18n('photo', 'Photo'),
					class: 'rses-option-media-thumb'
				})
			);
		} else if (kind === 'audio') {
			$preview.append(
				$('<span>', {
					class: 'rses-option-media-chip',
					text: rsesI18n('audio', 'Audio') + (attachment.filename ? ': ' + attachment.filename : '')
				})
			);
		} else if (kind === 'video') {
			$preview.append(
				$('<span>', {
					class: 'rses-option-media-chip',
					text: rsesI18n('video', 'Video') + (attachment.filename ? ': ' + attachment.filename : '')
				})
			);
		} else {
			$preview.append(
				$('<span>', {
					class: 'rses-option-media-chip',
					text: rsesI18n('mediaAttached', 'Media attached')
				})
			);
		}
	}

	function rsesOpenOptionMediaFrame($row) {
		if (typeof wp === 'undefined' || !wp.media) {
			window.alert('WordPress media library is unavailable on this screen.');
			return;
		}

		var $btn = $row.find('.rses-option-media-pick');
		var frame = wp.media({
			title: $btn.data('rses-title') || rsesI18n('selectMedia', 'Select option media'),
			button: {
				text: $btn.data('rses-button') || rsesI18n('useMedia', 'Use this media')
			},
			multiple: false,
			library: {
				type: ['image', 'audio', 'video']
			}
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			var kind = rsesMediaKind(attachment.mime);
			if (!kind) {
				return;
			}
			rsesSetOptionMedia($row, attachment);
		});

		frame.open();
	}

	$(document).ready(function () {
		$('.rses-key-card .button-warning').on('click', function () {
			return confirm(
				'WARNING: Full private key export is a significant security risk. Continue?'
			);
		});

		$(document).on('click', '.rses-copy-shortcode', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var text = $btn.attr('data-rses-copy') || '';
			var original = $btn.text();

			rsesCopyText(text).then(function () {
				$btn.text($btn.data('copied-label') || 'Copied!');
				window.setTimeout(function () {
					$btn.text(original);
				}, 1500);
			});
		});

		$(document).on('click', '.rses-copy-share', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var target = $btn.attr('data-rses-target');
			var $ta = target ? $('#' + target) : $();
			var text = $ta.length ? $ta.val() : '';
			var original = $btn.text();

			if (!text) {
				return;
			}

			rsesCopyText(text).then(function () {
				$btn.text($btn.data('copied-label') || 'Copied!');
				window.setTimeout(function () {
					$btn.text(original);
				}, 1500);
			});
		});

		$(document).on('focus', '.rses-shortcode-input, .rses-share-json-view', function () {
			this.select();
		});

		$(document).on('click', '.rses-option-media-pick', function (e) {
			e.preventDefault();
			rsesOpenOptionMediaFrame($(this).closest('.rses-option-row'));
		});

		$(document).on('click', '.rses-option-media-clear', function (e) {
			e.preventDefault();
			rsesSetOptionMedia($(this).closest('.rses-option-row'), null);
		});

		var $loginLogoPick = $('#rses_pick_login_logo');
		if ($loginLogoPick.length && typeof wp !== 'undefined' && wp.media) {
			var $logoInput = $('#rses_login_logo_attachment_id');
			var $logoPreview = $('#rses_login_logo_preview');
			var defaultLogo = $logoPreview.find('img').attr('src');

			$loginLogoPick.on('click', function (e) {
				e.preventDefault();
				var frame = wp.media({
					title: rsesI18n('selectLoginLogo', 'Choose login logo'),
					button: { text: rsesI18n('useLoginLogo', 'Use this logo') },
					multiple: false,
					library: { type: 'image' }
				});

				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var thumb =
						(attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) ||
						(attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) ||
						attachment.url;
					$logoInput.val(String(attachment.id));
					$logoPreview.html($('<img>', { src: thumb, alt: '', width: 80, height: 80 }));
				});

				frame.open();
			});

			$('#rses_clear_login_logo').on('click', function (e) {
				e.preventDefault();
				$logoInput.val('0');
				if (defaultLogo) {
					$logoPreview.html($('<img>', { src: defaultLogo, alt: '', width: 80, height: 80 }));
				}
			});
		}

		var $adminLogoPick = $('#rses_pick_admin_logo');
		if ($adminLogoPick.length && typeof wp !== 'undefined' && wp.media) {
			var $adminInput = $('#rses_admin_logo_attachment_id');
			var $adminPreview = $('#rses_admin_logo_preview');
			var $adminImg = $adminPreview.find('img').first();
			var adminDefault = $adminImg.data('rses-default-src') || $adminImg.attr('src');

			$adminLogoPick.on('click', function (e) {
				e.preventDefault();
				var frame = wp.media({
					title: rsesI18n('selectAdminLogo', 'Choose admin logo'),
					button: { text: rsesI18n('useAdminLogo', 'Use this logo') },
					multiple: false,
					library: { type: 'image' }
				});

				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var url =
						(attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) ||
						(attachment.sizes && attachment.sizes.full && attachment.sizes.full.url) ||
						attachment.url;
					$adminInput.val(String(attachment.id));
					$adminPreview.html(
						$('<img>', {
							src: url,
							alt: '',
							class: 'rses-admin-logo-preview-img',
							'data-rses-default-src': adminDefault
						})
					);
				});

				frame.open();
			});

			$('#rses_clear_admin_logo').on('click', function (e) {
				e.preventDefault();
				$adminInput.val('0');
				if (adminDefault) {
					$adminPreview.html(
						$('<img>', {
							src: adminDefault,
							alt: '',
							class: 'rses-admin-logo-preview-img',
							'data-rses-default-src': adminDefault
						})
					);
				}
			});
		}

		var $roundAudioPick = $('#rses-round-audio-pick');
		if ($roundAudioPick.length && typeof wp !== 'undefined' && wp.media) {
			var $audioInput = $('#rses_round_end_audio_id');
			var $audioPreview = $('#rses-round-audio-preview');
			var $audioClear = $('#rses-round-audio-clear');

			$roundAudioPick.on('click', function (e) {
				e.preventDefault();
				var frame = wp.media({
					title: $roundAudioPick.data('rses-title') || 'Select audio',
					button: { text: $roundAudioPick.data('rses-button') || 'Use this audio' },
					multiple: false,
					library: { type: 'audio' }
				});
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					$audioInput.val(String(attachment.id));
					$audioPreview
						.removeAttr('hidden')
						.show()
						.empty()
						.append(
							$('<audio>', {
								controls: true,
								preload: 'metadata',
								src: attachment.url
							})
						);
					$audioClear.removeAttr('hidden').show();
				});
				frame.open();
			});

			$audioClear.on('click', function (e) {
				e.preventDefault();
				$audioInput.val('0');
				$audioPreview.attr('hidden', true).hide().empty();
				$audioClear.attr('hidden', true).hide();
			});
		}
	});
})(jQuery);
