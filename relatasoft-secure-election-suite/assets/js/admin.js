/**
 * RelataSoft Secure Election Suite - Admin JS
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		$('.rses-key-card .button-warning').on('click', function () {
			return confirm(
				'WARNING: Full private key export is a significant security risk. Continue?'
			);
		});
	});
})(jQuery);
