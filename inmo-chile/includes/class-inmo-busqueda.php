<?php
/**
 * Buscador público: filtros en la URL (compartibles y conservados al volver desde una ficha).
 * /propiedades/?operacion=arriendo&comuna=nunoa&tipo=departamento&precio_max=800000&moneda=CLP
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Busqueda {

	const ORDENES = array(
		'recientes'   => 'Más recientes',
		'precio_asc'  => 'Menor precio',
		'precio_desc' => 'Mayor precio',
	);

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'filtrar' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirigir_codigo' ) );
	}

	/** Filtros activos, saneados. */
	public static function filtros() {
		$op = Inmo_Util::get( 'operacion' );
		$op = array( 'comprar' => 'venta', 'arrendar' => 'arriendo' )[ $op ] ?? $op;
		$f  = array(
			'operacion'   => isset( Inmo_Esquema::OPERACIONES[ $op ] ) ? $op : '',
			'tipo'        => isset( Inmo_Esquema::TIPOS[ Inmo_Util::get( 'tipo' ) ] ) ? Inmo_Util::get( 'tipo' ) : '',
			'comuna'      => sanitize_title( Inmo_Util::get( 'comuna' ) ),
			'precio_min'  => Inmo_Util::parse_numero( Inmo_Util::get( 'precio_min' ) ),
			'precio_max'  => Inmo_Util::parse_numero( Inmo_Util::get( 'precio_max' ) ),
			'moneda'      => isset( Inmo_Esquema::MONEDAS[ Inmo_Util::get( 'moneda' ) ] ) ? Inmo_Util::get( 'moneda' ) : '',
			'dormitorios' => absint( Inmo_Util::get( 'dormitorios' ) ),
			'banos'       => absint( Inmo_Util::get( 'banos' ) ),
			'orden'       => isset( self::ORDENES[ Inmo_Util::get( 'orden' ) ] ) ? Inmo_Util::get( 'orden' ) : 'recientes',
		);
		if ( ! $f['moneda'] ) {
			$f['moneda'] = $f['operacion'] ? Inmo_Esquema::moneda_defecto( $f['operacion'] ) : 'UF';
		}
		return $f;
	}

	/** Cantidad de filtros activos (para el botón "Filtros (n)" en móvil). */
	public static function n_activos( array $f ) {
		$n = 0;
		foreach ( array( 'operacion', 'tipo', 'comuna', 'precio_min', 'precio_max', 'dormitorios', 'banos' ) as $k ) {
			if ( '' !== $f[ $k ] && 0 !== $f[ $k ] ) {
				$n++;
			}
		}
		return $n;
	}

	public static function filtrar( WP_Query $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_post_type_archive( INMO_CPT ) ) {
			return;
		}
		$f = self::filtros();
		$q->set( 'posts_per_page', (int) Inmo_Ajustes::get( 'resultados_pagina', 12 ) );
		$q->set( 'post_status', 'publish' );

		$tax = array();
		if ( $f['operacion'] ) {
			$tax[] = array( 'taxonomy' => Inmo_Propiedades::TAX_OPERACION, 'field' => 'slug', 'terms' => $f['operacion'] );
		}
		if ( $f['tipo'] ) {
			$tax[] = array( 'taxonomy' => Inmo_Propiedades::TAX_TIPO, 'field' => 'slug', 'terms' => $f['tipo'] );
		}
		if ( $f['comuna'] ) {
			$tax[] = array( 'taxonomy' => Inmo_Propiedades::TAX_UBICACION, 'field' => 'slug', 'terms' => $f['comuna'] );
		}
		if ( $tax ) {
			$q->set( 'tax_query', array_merge( array( 'relation' => 'AND' ), $tax ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$meta = array(
			'relation' => 'AND',
			array( 'key' => '_inmo_estado', 'value' => Inmo_Esquema::ESTADOS_PUBLICABLES, 'compare' => 'IN' ),
			array( 'key' => '_inmo_visibilidad', 'value' => 'publica' ),
		);
		$factor = 'UF' === $f['moneda'] ? Inmo_Ajustes::uf() : 1;
		if ( '' !== $f['precio_min'] ) {
			$meta[] = array( 'key' => '_inmo_precio_clp', 'value' => (float) $f['precio_min'] * $factor, 'compare' => '>=', 'type' => 'NUMERIC' );
		}
		if ( '' !== $f['precio_max'] ) {
			$meta[] = array( 'key' => '_inmo_precio_clp', 'value' => (float) $f['precio_max'] * $factor, 'compare' => '<=', 'type' => 'NUMERIC' );
		}
		if ( $f['dormitorios'] ) {
			$meta[] = array( 'key' => '_inmo_dormitorios', 'value' => $f['dormitorios'], 'compare' => '>=', 'type' => 'NUMERIC' );
		}
		if ( $f['banos'] ) {
			$meta[] = array( 'key' => '_inmo_banos', 'value' => $f['banos'], 'compare' => '>=', 'type' => 'NUMERIC' );
		}
		if ( 'recientes' !== $f['orden'] ) {
			$meta['precio'] = array( 'key' => '_inmo_precio_clp', 'type' => 'NUMERIC' );
			$q->set( 'orderby', array( 'precio' => 'precio_asc' === $f['orden'] ? 'ASC' : 'DESC' ) );
		}
		$q->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/** Si se busca un código exacto (?codigo=CP-0001), ir directo a la ficha. */
	public static function redirigir_codigo() {
		$codigo = Inmo_Util::get( 'codigo' );
		if ( ! $codigo ) {
			return;
		}
		$id = Inmo_Propiedades::por_codigo( $codigo );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			wp_safe_redirect( get_permalink( $id ) );
			exit;
		}
	}

	/** Propiedades destacadas (selección administrable desde la ficha interna). */
	public static function destacadas( $n = 6 ) {
		return get_posts(
			array(
				'post_type'      => INMO_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => $n,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => '_inmo_destacado', 'value' => '1' ),
					array( 'key' => '_inmo_estado', 'value' => Inmo_Esquema::ESTADOS_PUBLICABLES, 'compare' => 'IN' ),
					array( 'key' => '_inmo_visibilidad', 'value' => 'publica' ),
				),
			)
		);
	}

	/**
	 * Comunas agrupadas por región para los selectores.
	 *
	 * @param bool $solo_con_publicadas true = solo comunas con propiedades publicadas (buscador público).
	 * @return array [ region => [ slug|term_id => nombre ] ]
	 */
	public static function comunas( $solo_con_publicadas = true, $por_id = false ) {
		$regiones = get_terms( array( 'taxonomy' => Inmo_Propiedades::TAX_UBICACION, 'parent' => 0, 'hide_empty' => false, 'orderby' => 'name' ) );
		$out      = array();
		if ( is_wp_error( $regiones ) ) {
			return $out;
		}
		$con = $solo_con_publicadas ? self::comunas_publicadas() : null;
		foreach ( $regiones as $r ) {
			$hijos = get_terms( array( 'taxonomy' => Inmo_Propiedades::TAX_UBICACION, 'parent' => $r->term_id, 'hide_empty' => false, 'orderby' => 'name' ) );
			foreach ( (array) $hijos as $h ) {
				if ( null !== $con && ! isset( $con[ $h->term_id ] ) ) {
					continue;
				}
				$out[ $r->name ][ $por_id ? $h->term_id : $h->slug ] = $h->name;
			}
		}
		return $out;
	}

	private static function comunas_publicadas() {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tt.term_id FROM {$wpdb->term_relationships} tr
				JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tt.taxonomy = %s AND p.post_type = %s AND p.post_status = 'publish'",
				Inmo_Propiedades::TAX_UBICACION,
				INMO_CPT
			)
		);
		return array_flip( array_map( 'intval', $ids ) );
	}

	/** URL de resultados con filtros. */
	public static function url( array $f = array() ) {
		$f = array_filter( $f, static function ( $v ) { return '' !== $v && null !== $v && 0 !== $v; } );
		if ( isset( $f['orden'] ) && 'recientes' === $f['orden'] ) {
			unset( $f['orden'] );
		}
		return add_query_arg( array_map( 'rawurlencode', $f ), get_post_type_archive_link( INMO_CPT ) );
	}
}
