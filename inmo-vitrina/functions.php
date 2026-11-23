<?php
/**
 * Tema Inmo Vitrina — presentación pública. Toda la lógica de negocio vive en el plugin Inmo Chile.
 *
 * @package InmoVitrina
 */

defined( 'ABSPATH' ) || exit;

define( 'INMO_VITRINA_VERSION', '0.1.0' );

add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
		add_theme_support( 'custom-logo' );
		add_image_size( 'inmo-tarjeta', 640, 427, true );
		add_image_size( 'inmo-galeria', 1200, 800, true );
		register_nav_menus( array( 'principal' => 'Menú principal', 'pie' => 'Menú del pie' ) );
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'inmo-vitrina', get_stylesheet_uri(), array(), INMO_VITRINA_VERSION );
		wp_add_inline_style( 'inmo-vitrina', inmo_vitrina_css_marca() );
		wp_enqueue_script( 'inmo-vitrina', get_template_directory_uri() . '/assets/vitrina.js', array(), INMO_VITRINA_VERSION, true );
	}
);

/** ¿Está activo el plugin? El tema degrada con un aviso si no. */
function inmo_vitrina_plugin() {
	return function_exists( 'inmo_propiedad' );
}

/** Ajuste de marca con respaldo. */
function inmo_vitrina_marca( $clave, $defecto = '' ) {
	if ( inmo_vitrina_plugin() ) {
		return inmo_ajuste( $clave, $defecto );
	}
	return 'marca_nombre' === $clave ? get_bloginfo( 'name' ) : $defecto;
}

/** Colores de marca configurables → variables CSS. */
function inmo_vitrina_css_marca() {
	$p = sanitize_hex_color( inmo_vitrina_marca( 'color_primario', '#1f4e5f' ) );
	$a = sanitize_hex_color( inmo_vitrina_marca( 'color_acento', '#d9822b' ) );
	return ':root{--marca:' . ( $p ? $p : '#1f4e5f' ) . ';--acento:' . ( $a ? $a : '#d9822b' ) . ';}';
}

/** Menú por defecto si no se ha configurado uno. */
function inmo_vitrina_menu_defecto() {
	$items = array(
		array( 'Comprar', add_query_arg( 'operacion', 'venta', get_post_type_archive_link( 'inmo_propiedad' ) ) ),
		array( 'Arrendar', add_query_arg( 'operacion', 'arriendo', get_post_type_archive_link( 'inmo_propiedad' ) ) ),
		array( 'Vende o arrienda tu propiedad', inmo_vitrina_url_pagina( 'inmo_pagina_propietarios', '/publica-tu-propiedad/' ) ),
		array( 'Contacto', inmo_vitrina_url_pagina( 'inmo_pagina_contacto', '/contacto/' ) ),
	);
	echo '<ul class="menu">';
	foreach ( $items as $i ) {
		echo '<li><a href="' . esc_url( $i[1] ) . '">' . esc_html( $i[0] ) . '</a></li>';
	}
	echo '</ul>';
}

function inmo_vitrina_url_pagina( $opcion, $defecto ) {
	$id = (int) get_option( $opcion );
	return $id ? get_permalink( $id ) : home_url( $defecto );
}

/** Imagen de la propiedad con texto alternativo útil. */
function inmo_vitrina_imagen( $att_id, $size, $alt, $attrs = array() ) {
	if ( ! $att_id ) {
		return '<div class="sin-foto" role="img" aria-label="Sin foto">Sin foto</div>';
	}
	return wp_get_attachment_image( $att_id, $size, false, array_merge( array( 'alt' => $alt, 'loading' => 'lazy', 'decoding' => 'async' ), $attrs ) );
}

/** Formulario de búsqueda (portada y resultados). */
function inmo_vitrina_buscador( $f = array(), $variante = 'portada' ) {
	set_query_var( 'inmo_filtros', $f );
	set_query_var( 'inmo_variante', $variante );
	get_template_part( 'template-parts/buscador' );
}

/** Meta descripción simple para fichas y resultados. */
add_action(
	'wp_head',
	static function () {
		if ( is_singular( 'inmo_propiedad' ) && inmo_vitrina_plugin() ) {
			$v = inmo_propiedad();
			if ( $v ) {
				$d = $v['tipo_label'] . ' en ' . strtolower( $v['operacion_label'] ) . ' en ' . $v['comuna'] . '. ' . $v['precio_texto'] . '. Código ' . $v['codigo'] . '.';
				echo '<meta name="description" content="' . esc_attr( $d ) . '">' . "\n";
				if ( $v['portada'] ) {
					$img = wp_get_attachment_image_url( $v['portada'], 'inmo-galeria' );
					echo '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
				}
				echo '<meta property="og:title" content="' . esc_attr( $v['titulo'] ) . '">' . "\n";
			}
		}
	},
	5
);

/** Título de resultados según filtros: «Departamentos en arriendo en Ñuñoa». */
function inmo_vitrina_titulo_resultados() {
	$f      = Inmo_Busqueda::filtros();
	$plural = array( 'departamento' => 'Departamentos', 'casa' => 'Casas', 'oficina' => 'Oficinas', 'local' => 'Locales comerciales', 'terreno' => 'Terrenos' );
	$t      = $f['tipo'] ? $plural[ $f['tipo'] ] : 'Propiedades';
	if ( $f['operacion'] ) {
		$t .= ' en ' . strtolower( Inmo_Esquema::OPERACIONES[ $f['operacion'] ] );
	}
	if ( $f['comuna'] ) {
		$term = get_term_by( 'slug', $f['comuna'], 'inmo_ubicacion' );
		$t   .= ' en ' . ( $term ? $term->name : $f['comuna'] );
	}
	return $t;
}

add_filter(
	'document_title_parts',
	static function ( $t ) {
		if ( is_post_type_archive( 'inmo_propiedad' ) && inmo_vitrina_plugin() ) {
			$t['title'] = inmo_vitrina_titulo_resultados();
		}
		return $t;
	}
);
