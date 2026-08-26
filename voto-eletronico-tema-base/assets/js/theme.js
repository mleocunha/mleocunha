/**
 * Voto Eletrônico - Tema Base front script.
 */
(function () {
	'use strict';

	document.documentElement.classList.add('vetb-js');

	// Respect reduced motion: stop spinning pinwheels.
	if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		document.querySelectorAll('.vetb-pinwheel--spin').forEach(function (el) {
			el.classList.remove('vetb-pinwheel--spin');
		});
	}
})();
