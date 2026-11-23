/* Inmo Chile — comportamiento accesible de formularios (funcionan también sin JS) */
(function () {
	'use strict';
	// Llevar el foco al mensaje de error/éxito tras enviar.
	document.addEventListener('DOMContentLoaded', function () {
		var foco = document.querySelector('[data-inmo-foco]');
		if (foco) {
			foco.focus();
			foco.scrollIntoView({ block: 'center' });
		}
	});

	// Pestañas (patrón ARIA: flechas izquierda/derecha, Inicio/Fin).
	document.querySelectorAll('.inmo-tabs').forEach(function (lista) {
		var tabs = Array.prototype.slice.call(lista.querySelectorAll('[role=tab]'));
		function activar(tab, foco) {
			tabs.forEach(function (t) {
				var sel = t === tab;
				t.setAttribute('aria-selected', sel ? 'true' : 'false');
				t.tabIndex = sel ? 0 : -1;
				document.getElementById(t.getAttribute('aria-controls')).hidden = !sel;
			});
			if (foco) { tab.focus(); }
		}
		tabs.forEach(function (t, i) {
			t.addEventListener('click', function () { activar(t, false); });
			t.addEventListener('keydown', function (e) {
				var n = null;
				if (e.key === 'ArrowRight') { n = tabs[(i + 1) % tabs.length]; }
				if (e.key === 'ArrowLeft') { n = tabs[(i - 1 + tabs.length) % tabs.length]; }
				if (e.key === 'Home') { n = tabs[0]; }
				if (e.key === 'End') { n = tabs[tabs.length - 1]; }
				if (n) { e.preventDefault(); activar(n, true); }
			});
		});
		// Enlaces externos que abren una pestaña (p. ej. botón "Solicitar visita").
		document.querySelectorAll('[data-inmo-abrir-tab]').forEach(function (a) {
			a.addEventListener('click', function () {
				var t = document.getElementById(a.getAttribute('data-inmo-abrir-tab'));
				if (t && lista.contains(t)) { activar(t, true); }
			});
		});
	});

	// Límite de fotos.
	document.querySelectorAll('input[type=file][data-inmo-max]').forEach(function (inp) {
		inp.addEventListener('change', function () {
			var max = parseInt(inp.getAttribute('data-inmo-max'), 10);
			if (inp.files.length > max) {
				inp.setCustomValidity('Puedes subir hasta ' + max + ' fotos.');
				inp.reportValidity();
			} else {
				inp.setCustomValidity('');
			}
		});
	});

	// Validación nativa con mensajes en español al enviar.
	document.querySelectorAll('form[data-inmo-form]').forEach(function (f) {
		f.addEventListener('submit', function (e) {
			var inval = f.querySelector(':invalid');
			if (inval) {
				e.preventDefault();
				inval.focus();
				inval.reportValidity();
			}
		});
	});
})();
