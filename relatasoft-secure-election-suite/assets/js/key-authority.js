/**
 * Key Authority admin interactions.
 */
(function ($) {
	'use strict';
	$(document).ready(function () {
		$('#rses_key_size').on('change', function () {
			var bits = parseInt($(this).val(), 10);
			if (bits >= 3072) {
				$('.rses-key-size-warning').remove();
				$(this).after(
					'<p class="rses-key-size-warning description">Key generation at ' +
						bits +
						' bits may be slow in PHP.</p>'
				);
			}
		});
	});
})(jQuery);
