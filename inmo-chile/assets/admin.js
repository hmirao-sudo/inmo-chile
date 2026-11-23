/* Inmo Chile — admin */
(function ($) {
	'use strict';

	// Campos según tipo de inmueble y operación.
	function aplicarCondiciones() {
		var tipo = $('#inmo_tipo').val() || '';
		var op = $('input[name="inmo_operacion"]:checked').val() || '';
		$('.inmo-cond').each(function () {
			var tipos = ($(this).data('tipos') || '').toString().split(' ').filter(Boolean);
			var ops = ($(this).data('ops') || '').toString().split(' ').filter(Boolean);
			var ok = (!tipos.length || !tipo || tipos.indexOf(tipo) > -1) && (!ops.length || !op || ops.indexOf(op) > -1);
			$(this).toggle(ok).find('input,select,textarea').prop('disabled', false);
		});
		var $lbl = $('#inmo_precio_label');
		if ($lbl.length && op) {
			$lbl.text($lbl.data(op));
		}
		// Estados según operación.
		$('#inmo_estado option[value="vendida"]').prop('hidden', op === 'arriendo');
		$('#inmo_estado option[value="arrendada"]').prop('hidden', op === 'venta');
	}
	$(document).on('change', '#inmo_tipo, input[name="inmo_operacion"]', aplicarCondiciones);
	$(document).on('change', 'input[name="inmo_operacion"]', function () {
		if (!$('#inmo_precio').val()) {
			$('#inmo_moneda').val(this.value === 'arriendo' ? 'CLP' : 'UF');
		}
	});
	$(aplicarCondiciones);

	// Galería.
	function syncGaleria() {
		var ids = $('#inmo-galeria li').map(function () { return $(this).data('id'); }).get();
		$('#inmo_galeria_ids').val(ids.join(','));
	}
	$(function () {
		var $g = $('#inmo-galeria');
		if ($g.length && $.fn.sortable) {
			$g.sortable({ update: syncGaleria });
		}
	});
	$(document).on('click', '.inmo-quitar', function () {
		$(this).closest('li').remove();
		syncGaleria();
	});
	var frame;
	$(document).on('click', '#inmo-agregar-fotos', function (e) {
		e.preventDefault();
		if (frame) { frame.open(); return; }
		frame = wp.media({ title: 'Fotos autorizadas para publicar', button: { text: 'Agregar a la galería' }, library: { type: 'image' }, multiple: 'add' });
		frame.on('select', function () {
			frame.state().get('selection').each(function (att) {
				var a = att.toJSON();
				if ($('#inmo-galeria li[data-id="' + a.id + '"]').length) { return; }
				var src = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
				$('#inmo-galeria').append('<li data-id="' + a.id + '"><img src="' + src + '" alt=""><button type="button" class="inmo-quitar" aria-label="Quitar foto">×</button></li>');
			});
			syncGaleria();
		});
		frame.open();
	});

	// La ficha usa sus propios botones (no los de #submitpost): al enviar, quitar el aviso
	// «¿Salir del sitio?» de WordPress, que de otro modo cancela el guardado.
	$(document).on('submit', 'form#post', function () {
		$(window).off('beforeunload.edit-post');
		if (window.wp && wp.autosave && wp.autosave.server) { wp.autosave.server.suspend(); }
	});

	// Copiar enlace de invitación.
	$(document).on('click', '[data-inmo-copiar]', function () {
		var $i = $(this).prevAll('input').first();
		$i.trigger('select');
		if (navigator.clipboard) { navigator.clipboard.writeText($i.val()); }
		$(this).text('Copiado');
	});
})(jQuery);
