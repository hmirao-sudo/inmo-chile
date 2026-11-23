<?php
/**
 * Portada: buscador primero, selección destacada, propietarios y valorización.
 *
 * @package InmoVitrina
 */

get_header();
$url_prop = inmo_vitrina_url_pagina( 'inmo_pagina_propietarios', '/publica-tu-propiedad/' );
?>
<section class="hero">
	<div class="contenedor">
		<h1 class="hero__titulo">Encuentra tu próximo hogar en Santiago</h1>
		<p class="hero__bajada">Casas, departamentos, oficinas, locales y terrenos en venta y arriendo, con atención de corredores que conocen el barrio.</p>
		<?php if ( inmo_vitrina_plugin() ) { inmo_vitrina_buscador( array(), 'portada' ); } ?>
	</div>
</section>

<?php if ( inmo_vitrina_plugin() ) : ?>
	<?php $destacadas = Inmo_Busqueda::destacadas( 6 ); ?>
	<section class="seccion contenedor" aria-labelledby="t-destacadas">
		<div class="seccion__cabecera">
			<h2 id="t-destacadas">Propiedades seleccionadas</h2>
			<a class="enlace-flecha" href="<?php echo esc_url( get_post_type_archive_link( 'inmo_propiedad' ) ); ?>">Ver todas</a>
		</div>
		<?php if ( $destacadas ) : ?>
			<div class="grilla">
				<?php
				global $post;
				foreach ( $destacadas as $post ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride
					setup_postdata( $post );
					get_template_part( 'template-parts/tarjeta' );
				}
				wp_reset_postdata();
				?>
			</div>
		<?php else : ?>
			<p>Pronto publicaremos nuevas propiedades.</p>
		<?php endif; ?>
	</section>
<?php endif; ?>

<section class="seccion seccion--recorridos">
	<div class="contenedor recorridos">
		<article class="recorrido">
			<h2>Busco dónde vivir</h2>
			<p>Filtra por comuna, tipo y precio. Guarda o comparte tu búsqueda con un enlace.</p>
			<a class="boton" href="<?php echo esc_url( get_post_type_archive_link( 'inmo_propiedad' ) ); ?>">Explorar propiedades</a>
		</article>
		<article class="recorrido">
			<h2>Quiero visitar una propiedad</h2>
			<p>Desde cada ficha puedes pedir una visita. Un corredor la confirma contigo antes de agendarla.</p>
			<a class="boton" href="<?php echo esc_url( add_query_arg( 'operacion', 'arriendo', get_post_type_archive_link( 'inmo_propiedad' ) ) ); ?>">Ver arriendos</a>
		</article>
		<article class="recorrido recorrido--destacado">
			<h2>Tengo una propiedad</h2>
			<p>Te ayudamos a venderla o arrendarla: publicación, visitas, contratos y cierre. Cuéntanos lo básico en dos minutos.</p>
			<a class="boton boton--primario" href="<?php echo esc_url( $url_prop ); ?>">Entregar mi propiedad en corretaje</a>
			<a class="enlace-flecha" href="<?php echo esc_url( add_query_arg( 'quiero', 'valorizar', $url_prop ) ); ?>">Solicitar una valorización</a>
		</article>
	</div>
</section>
<?php
get_footer();
