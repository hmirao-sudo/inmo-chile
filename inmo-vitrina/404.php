<?php
/**
 * 404.
 *
 * @package InmoVitrina
 */

get_header();
?>
<div class="contenedor contenedor--estrecho pagina">
	<h1>No encontramos esta página</h1>
	<p>Es posible que la propiedad ya no esté publicada (por ejemplo, porque se vendió o arrendó).</p>
	<p><a class="boton boton--primario" href="<?php echo esc_url( function_exists( 'inmo_propiedad' ) ? get_post_type_archive_link( 'inmo_propiedad' ) : home_url( '/' ) ); ?>">Ver propiedades disponibles</a></p>
</div>
<?php
get_footer();
