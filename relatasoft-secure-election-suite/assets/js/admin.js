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

		$(document).on('focus', '.rses-shortcode-input', function () {
			this.select();
		});
	});
})(jQuery);
