/**
 * Frontend voting booth UX (presentation only).
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

	function rsesSyncChoiceState($choice) {
		var $input = $choice.find('.rses-choice-input').first();
		if (!$input.length) {
			return;
		}
		$choice.toggleClass('is-checked', $input.prop('checked'));
	}

	function rsesSyncQuestion($question) {
		$question.find('.rses-choice').each(function () {
			rsesSyncChoiceState($(this));
		});
	}

	$(function () {
		var $form = $('#rses-ballot-form');

		$form.find('.rses-choice').each(function () {
			rsesSyncChoiceState($(this));
		});

		$form.on('change', '.rses-choice-input', function () {
			var $question = $(this).closest('.rses-question');
			rsesSyncQuestion($question);
		});

		$form.on('keydown', '.rses-choice', function (e) {
			if ($(e.target).closest('.rses-option-media').length) {
				return;
			}
			if (e.key !== ' ' && e.key !== 'Enter') {
				return;
			}
			e.preventDefault();
			$(this).find('.rses-choice-input').trigger('click');
		});

		// Keep audio/video controls from fighting keyboard choice activation.
		$form.on('click', '.rses-option-media--audio, .rses-option-media--video', function (e) {
			e.stopPropagation();
		});

		$form.on('submit', function (e) {
			var msg =
				(window.rsesVoting && window.rsesVoting.i18n && window.rsesVoting.i18n.confirm) ||
				'Submit your encrypted vote? This cannot be undone.';
			if (!window.confirm(msg)) {
				e.preventDefault();
			}
		});

		$(document).on('click', '.rses-copy-receipt', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var target = $btn.attr('data-rses-target');
			var text = target ? $('#' + target).text() : '';
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
	});
})(jQuery);
