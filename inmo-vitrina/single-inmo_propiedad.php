<?php
/**
 * Ficha pública. Solo usa inmo_propiedad() (modelo sin datos privados).
 *
 * @package InmoVitrina
 */

get_header();
while ( have_posts() ) :
	the_post();
	$v = inmo_vitrina_plugin() ? inmo_propiedad() : null;
	if ( ! $v ) {
		continue;
	}
	$alt_base = $v['tipo_label'] . ' en ' . $v['comuna'];
	?>
	<article class="ficha contenedor">
		<nav class="migas" aria-label="Ruta">
			<a href="<?php echo esc_url( get_post_type_archive_link( 'inmo_propiedad' ) ); ?>" data-volver-resultados>← Volver a los resultados</a>
		</nav>
		<header class="ficha__cabecera">
			<div>
				<p class="ficha__etiquetas">
					<span class="etiqueta etiqueta--<?php echo esc_attr( $v['operacion'] ); ?>"><?php echo esc_html( $v['operacion_label'] ); ?></span>
					<span class="etiqueta etiqueta--neutra"><?php echo esc_html( $v['tipo_label'] ); ?></span>
					<?php if ( $v['reservada'] ) : ?><span class="etiqueta etiqueta--reservada">Reservada</span><?php endif; ?>
				</p>
				<h1 class="ficha__titulo"><?php echo esc_html( $v['titulo'] ); ?></h1>
				<p class="ficha__lugar"><?php echo esc_html( trim( ( $v['sector'] ? $v['sector'] . ', ' : '' ) . $v['comuna'] . ( $v['region'] ? ', ' . $v['region'] : '' ) ) ); ?></p>
			</div>
			<div class="ficha__precio">
				<p class="ficha__precio-label"><?php echo esc_html( $v['precio_label'] ); ?></p>
				<p class="ficha__precio-valor"><?php echo esc_html( $v['precio_texto'] ); ?></p>
				<?php if ( $v['equivalencia'] ) : ?>
					<p class="ficha__equivalencia"><?php echo esc_html( $v['equivalencia'] ); ?><br><small>Fuente: <?php echo esc_html( $v['equivalencia_fuente'] ); ?>. Referencial.</small></p>
				<?php endif; ?>
				<?php if ( $v['gastos_comunes'] ) : ?><p class="ficha__gc">Gastos comunes: <?php echo esc_html( $v['gastos_comunes'] ); ?> aprox.</p><?php endif; ?>
				<p class="ficha__codigo">Código <strong><?php echo esc_html( $v['codigo'] ); ?></strong></p>
			</div>
		</header>

		<?php if ( $v['fotos'] ) : ?>
			<section class="galeria" aria-roledescription="carrusel" aria-label="Fotos de la propiedad" data-galeria>
				<div class="galeria__principal">
					<?php foreach ( $v['fotos'] as $i => $foto ) : ?>
						<figure class="galeria__slide" id="foto-<?php echo (int) $i; ?>" role="group" aria-roledescription="foto" aria-label="<?php echo esc_attr( ( $i + 1 ) . ' de ' . count( $v['fotos'] ) ); ?>" <?php echo $i ? 'hidden' : ''; ?>>
							<?php echo inmo_vitrina_imagen( $foto, 'inmo-galeria', get_post_meta( $foto, '_wp_attachment_image_alt', true ) ?: $alt_base . ', foto ' . ( $i + 1 ), $i ? array() : array( 'loading' => 'eager', 'fetchpriority' => 'high' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</figure>
					<?php endforeach; ?>
					<?php if ( count( $v['fotos'] ) > 1 ) : ?>
						<button type="button" class="galeria__nav galeria__nav--prev" data-galeria-prev aria-label="Foto anterior">‹</button>
						<button type="button" class="galeria__nav galeria__nav--next" data-galeria-next aria-label="Foto siguiente">›</button>
						<p class="galeria__contador" aria-live="polite"><span data-galeria-actual>1</span> / <?php echo count( $v['fotos'] ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( count( $v['fotos'] ) > 1 ) : ?>
					<ul class="galeria__miniaturas">
						<?php foreach ( $v['fotos'] as $i => $foto ) : ?>
							<li><button type="button" data-galeria-ir="<?php echo (int) $i; ?>" aria-label="Ver foto <?php echo (int) ( $i + 1 ); ?>" <?php echo $i ? '' : 'aria-current="true"'; ?>><?php echo wp_get_attachment_image( $foto, 'thumbnail', false, array( 'alt' => '' ) ); ?></button></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<div class="ficha__layout">
			<div class="ficha__contenido">
				<?php
				$grupos = array( 'caracteristicas' => 'Características', 'arriendo' => 'Condiciones de arriendo', 'precio' => 'Gastos' );
				foreach ( $grupos as $g => $titulo ) :
					$attrs = array_filter( $v['atributos'], static function ( $a ) use ( $g ) { return $a['grupo'] === $g; } );
					if ( ! $attrs ) {
						continue;
					}
					?>
					<section class="bloque">
						<h2><?php echo esc_html( $titulo ); ?></h2>
						<dl class="atributos">
							<?php foreach ( $attrs as $a ) : ?>
								<div><dt><?php echo esc_html( $a['label'] ); ?></dt><dd><?php echo esc_html( $a['valor'] ); ?></dd></div>
							<?php endforeach; ?>
						</dl>
					</section>
				<?php endforeach; ?>

				<?php if ( trim( wp_strip_all_tags( $v['descripcion'] ) ) ) : ?>
					<section class="bloque">
						<h2>Descripción</h2>
						<div class="texto"><?php echo wp_kses_post( $v['descripcion'] ); ?></div>
					</section>
				<?php endif; ?>

				<?php if ( $v['equipamiento'] ) : ?>
					<section class="bloque">
						<h2>Equipamiento</h2>
						<ul class="equipamiento"><?php foreach ( $v['equipamiento'] as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
					</section>
				<?php endif; ?>

				<section class="bloque">
					<h2>Ubicación</h2>
					<?php if ( $v['ubicacion'] ) : ?>
						<?php
						$d    = 'exacta' === $v['ubicacion']['precision'] ? 0.004 : 0.012;
						$bbox = ( $v['ubicacion']['lng'] - $d ) . ',' . ( $v['ubicacion']['lat'] - $d * 0.8 ) . ',' . ( $v['ubicacion']['lng'] + $d ) . ',' . ( $v['ubicacion']['lat'] + $d * 0.8 );
						$src  = 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode( $bbox ) . '&layer=mapnik' . ( 'exacta' === $v['ubicacion']['precision'] ? '&marker=' . rawurlencode( $v['ubicacion']['lat'] . ',' . $v['ubicacion']['lng'] ) : '' );
						?>
						<p><?php echo 'exacta' === $v['ubicacion']['precision'] ? 'Ubicación de la propiedad.' : 'Ubicación aproximada: el mapa muestra el sector, no la dirección exacta.'; ?></p>
						<div class="mapa"><iframe title="Mapa del sector de la propiedad" src="<?php echo esc_url( $src ); ?>" loading="lazy" referrerpolicy="no-referrer"></iframe></div>
					<?php else : ?>
						<p><?php echo esc_html( ( $v['sector'] ? $v['sector'] . ', ' : '' ) . $v['comuna'] ); ?>. La dirección exacta se entrega al coordinar la visita.</p>
					<?php endif; ?>
				</section>
			</div>

			<aside class="ficha__lateral" aria-label="Contactar">
				<div class="panel-contacto">
					<?php echo do_shortcode( '[inmo_formulario_propiedad id="' . (int) $v['id'] . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			</aside>
		</div>
		<div class="ficha__acciones-movil">
			<a class="boton boton--primario" href="#inmo-contacto-propiedad" data-inmo-abrir-tab="inmo-tab-visita">Solicitar visita</a>
			<a class="boton" href="#inmo-contacto-propiedad" data-inmo-abrir-tab="inmo-tab-consulta">Consultar</a>
		</div>
	</article>
	<?php
endwhile;
get_footer();
