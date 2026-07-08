/**
 * Frontend voting booth JS.
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		$('#rses-ballot-form').on('submit', function (e) {
			if (!confirm('Submit your encrypted vote? This cannot be undone.')) {
				e.preventDefault();
			}
		});
	});
})(jQuery);
