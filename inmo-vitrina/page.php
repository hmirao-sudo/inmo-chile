<?php
/**
 * Páginas (propietarios, invitación, contacto, privacidad).
 *
 * @package InmoVitrina
 */

get_header();
while ( have_posts() ) :
	the_post();
	$es_propietarios = (int) get_option( 'inmo_pagina_propietarios' ) === get_the_ID();
	?>
	<article class="pagina contenedor contenedor--estrecho">
		<h1><?php the_title(); ?></h1>
		<?php if ( $es_propietarios ) : ?>
			<p class="bajada">Cuéntanos lo básico y un corredor te contactará. Después podrás agregar características y fotos con un enlace personal.</p>
			<ol class="pasos">
				<li><strong>Nos cuentas</strong> operación, comuna y datos básicos.</li>
				<li><strong>Revisamos</strong> y coordinamos una visita para fotos y mandato.</li>
				<li><strong>Publicamos</strong> en nuestro sitio y, si lo autorizas, en portales.</li>
			</ol>
		<?php endif; ?>
		<div class="texto"><?php the_content(); ?></div>
	</article>
	<?php
endwhile;
get_footer();
