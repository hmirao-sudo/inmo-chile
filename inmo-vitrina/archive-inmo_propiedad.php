<?php
/**
 * Resultados de búsqueda. Filtros en la URL: se conservan al volver desde una ficha y se pueden compartir.
 *
 * @package InmoVitrina
 */

get_header();
if ( ! inmo_vitrina_plugin() ) {
	get_footer();
	return;
}
global $wp_query;
$f      = Inmo_Busqueda::filtros();
$n      = Inmo_Busqueda::n_activos( $f );
$total  = (int) $wp_query->found_posts;
$base   = get_post_type_archive_link( 'inmo_propiedad' );
$actual = add_query_arg( array_map( 'rawurlencode', wp_unslash( $_GET ) ), $base ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
?>
<div class="contenedor resultados">
	<header class="resultados__cabecera">
		<h1><?php echo esc_html( inmo_vitrina_titulo_resultados() ); ?></h1>
		<p class="resultados__conteo" role="status"><?php echo esc_html( 1 === $total ? '1 propiedad encontrada' : $total . ' propiedades encontradas' ); ?></p>
	</header>

	<div class="resultados__layout">
		<aside class="filtros" aria-label="Filtros">
			<button class="boton filtros__abrir" type="button" aria-expanded="false" aria-controls="panel-filtros">
				Filtros<?php echo $n ? ' <span class="contador">(' . (int) $n . ')</span>' : ''; ?>
			</button>
			<div id="panel-filtros" class="filtros__panel">
				<?php inmo_vitrina_buscador( $f, 'resultados' ); ?>
				<?php if ( $n ) : ?><p><a class="enlace" href="<?php echo esc_url( $base ); ?>">Limpiar filtros</a></p><?php endif; ?>
			</div>
		</aside>

		<section class="resultados__lista" aria-label="Resultados">
			<div class="barra">
				<form method="get" action="<?php echo esc_url( $base ); ?>" class="barra__orden">
					<?php foreach ( $f as $k => $val ) : if ( 'orden' === $k || '' === $val || 0 === $val ) { continue; } ?>
						<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( is_float( $val ) ? $val + 0 : $val ); ?>">
					<?php endforeach; ?>
					<label for="orden">Ordenar por</label>
					<select id="orden" name="orden" data-autoenviar>
						<?php foreach ( Inmo_Busqueda::ORDENES as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['orden'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
					<noscript><button class="boton" type="submit">Ordenar</button></noscript>
				</form>
				<button class="boton boton--secundario" type="button" data-compartir="<?php echo esc_url( $actual ); ?>">Compartir búsqueda</button>
				<span class="sr" role="status" aria-live="polite" data-compartir-estado></span>
			</div>

			<?php if ( have_posts() ) : ?>
				<div class="grilla">
					<?php
					while ( have_posts() ) {
						the_post();
						get_template_part( 'template-parts/tarjeta' );
					}
					?>
				</div>
				<nav class="paginacion" aria-label="Páginas de resultados">
					<?php echo wp_kses_post( (string) paginate_links( array( 'prev_text' => '← Anteriores', 'next_text' => 'Siguientes →' ) ) ); ?>
				</nav>
			<?php else : ?>
				<div class="sin-resultados">
					<h2>No encontramos propiedades con estos filtros</h2>
					<p>Prueba con menos filtros:</p>
					<ul class="sugerencias">
						<?php
						$quitar = array( 'precio_max' => 'Quitar precio máximo', 'precio_min' => 'Quitar precio mínimo', 'dormitorios' => 'Cualquier cantidad de dormitorios', 'tipo' => 'Todos los tipos', 'comuna' => 'Todas las comunas' );
						foreach ( $quitar as $k => $l ) {
							if ( '' !== $f[ $k ] && 0 !== $f[ $k ] ) {
								$g = $f;
								$g[ $k ] = '';
								echo '<li><a href="' . esc_url( Inmo_Busqueda::url( $g ) ) . '">' . esc_html( $l ) . '</a></li>';
							}
						}
						?>
						<li><a href="<?php echo esc_url( $base ); ?>">Ver todas las propiedades</a></li>
					</ul>
					<div class="sin-resultados__aviso">
						<?php echo do_shortcode( '[inmo_formulario_general titulo="¿Te avisamos cuando aparezca algo así?" busqueda="' . esc_attr( http_build_query( array_filter( $f ) ) ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
			<?php endif; ?>
		</section>
	</div>
</div>
<?php
get_footer();
