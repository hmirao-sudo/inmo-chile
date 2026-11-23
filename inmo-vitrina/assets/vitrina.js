/* Inmo Vitrina — mejoras progresivas (todo funciona sin JS). */
(function () {
	'use strict';
	document.documentElement.classList.add('js');

	// Menú móvil.
	var mb = document.querySelector('.menu-boton');
	var nav = document.getElementById('menu-principal');
	if (mb && nav) {
		mb.addEventListener('click', function () {
			var abierto = mb.getAttribute('aria-expanded') === 'true';
			mb.setAttribute('aria-expanded', String(!abierto));
			nav.classList.toggle('abierta', !abierto);
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && nav.classList.contains('abierta')) {
				mb.setAttribute('aria-expanded', 'false');
				nav.classList.remove('abierta');
				mb.focus();
			}
		});
	}

	// Filtros en móvil.
	var fb = document.querySelector('.filtros__abrir');
	var fp = document.getElementById('panel-filtros');
	if (fb && fp) {
		fb.addEventListener('click', function () {
			var abierto = fb.getAttribute('aria-expanded') === 'true';
			fb.setAttribute('aria-expanded', String(!abierto));
			fp.classList.toggle('abierto', !abierto);
			if (!abierto) { var p = fp.querySelector('input,select'); if (p) { p.focus(); } }
		});
	}

	// Moneda sugerida al cambiar Comprar/Arrendar (si no hay precios escritos).
	document.querySelectorAll('.buscador').forEach(function (f) {
		f.querySelectorAll('input[name=operacion]').forEach(function (r) {
			r.addEventListener('change', function () {
				var sel = f.querySelector('[data-moneda-select]');
				var vacios = !f.querySelector('[name=precio_min]').value && !f.querySelector('[name=precio_max]').value;
				if (sel && vacios && r.dataset.moneda) { sel.value = r.dataset.moneda; }
			});
		});
		// No enviar parámetros vacíos: URLs limpias y compartibles.
		f.addEventListener('submit', function () {
			f.querySelectorAll('input,select').forEach(function (el) {
				if (!el.value && el.name) { el.disabled = true; }
			});
			var mon = f.querySelector('[data-moneda-select]');
			if (mon && !f.querySelector('[name=precio_min]').value && !f.querySelector('[name=precio_max]').value) { mon.disabled = true; }
		});
	});

	// Ordenar al cambiar.
	document.querySelectorAll('[data-autoenviar]').forEach(function (s) {
		s.addEventListener('change', function () { s.form.submit(); });
	});

	// Compartir búsqueda.
	document.querySelectorAll('[data-compartir]').forEach(function (b) {
		b.addEventListener('click', function () {
			var url = b.getAttribute('data-compartir');
			var estado = document.querySelector('[data-compartir-estado]');
			function avisar(t) { if (estado) { estado.textContent = t; } b.textContent = t; setTimeout(function () { b.textContent = 'Compartir búsqueda'; }, 2500); }
			if (navigator.share) {
				navigator.share({ title: document.title, url: url }).catch(function () {});
			} else if (navigator.clipboard) {
				navigator.clipboard.writeText(url).then(function () { avisar('Enlace copiado'); }, function () { window.prompt('Copia este enlace:', url); });
			} else {
				window.prompt('Copia este enlace:', url);
			}
		});
	});

	// Recordar la última búsqueda para «Volver a los resultados».
	try {
		if (document.body.classList.contains('post-type-archive-inmo_propiedad')) {
			sessionStorage.setItem('inmo_ultima_busqueda', location.href);
		}
		var volver = document.querySelector('[data-volver-resultados]');
		var ultima = sessionStorage.getItem('inmo_ultima_busqueda');
		if (volver && ultima && ultima.indexOf(location.origin) === 0) { volver.href = ultima; }
	} catch (e) { /* almacenamiento no disponible: se usa el enlace por defecto */ }

	// Galería accesible (botones, miniaturas, flechas del teclado).
	document.querySelectorAll('[data-galeria]').forEach(function (g) {
		var slides = g.querySelectorAll('.galeria__slide');
		var thumbs = g.querySelectorAll('[data-galeria-ir]');
		var actual = g.querySelector('[data-galeria-actual]');
		var i = 0;
		function ir(n) {
			i = (n + slides.length) % slides.length;
			slides.forEach(function (s, k) { s.hidden = k !== i; });
			thumbs.forEach(function (t, k) { if (k === i) { t.setAttribute('aria-current', 'true'); } else { t.removeAttribute('aria-current'); } });
			if (actual) { actual.textContent = i + 1; }
		}
		var p = g.querySelector('[data-galeria-prev]');
		var n = g.querySelector('[data-galeria-next]');
		if (p) { p.addEventListener('click', function () { ir(i - 1); }); }
		if (n) { n.addEventListener('click', function () { ir(i + 1); }); }
		thumbs.forEach(function (t) { t.addEventListener('click', function () { ir(parseInt(t.getAttribute('data-galeria-ir'), 10)); }); });
		g.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowLeft') { ir(i - 1); }
			if (e.key === 'ArrowRight') { ir(i + 1); }
		});
	});
})();
