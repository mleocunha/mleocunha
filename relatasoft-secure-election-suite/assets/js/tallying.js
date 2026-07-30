/**
 * Tallying platform admin JS.
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		$('.rses-import-card textarea[name="rses_share_json"]').on('blur', function () {
			try {
				JSON.parse($(this).val());
				$(this).removeClass('rses-invalid');
			} catch (err) {
				$(this).addClass('rses-invalid');
			}
		});
	});
})(jQuery);
