<?php
/**
 * Modelo de vista pública: ÚNICA puerta por la que el tema obtiene datos de una propiedad.
 * Nunca incluye titulares, RUT, dirección exacta, coordenadas exactas, mandato, comisión ni notas.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Vista_Publica {

	/**
	 * @param int|WP_Post|null $post
	 * @return array|null
	 */
	public static function de( $post = null ) {
		$post = get_post( $post );
		if ( ! $post || INMO_CPT !== $post->post_type ) {
			return null;
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
			return null;
		}
		$id     = $post->ID;
		$tipo   = Inmo_Propiedades::termino( $id, Inmo_Propiedades::TAX_TIPO );
		$op     = Inmo_Propiedades::termino( $id, Inmo_Propiedades::TAX_OPERACION );
		$comuna = Inmo_Propiedades::comuna( $id );
		$region = ( $comuna && $comuna->parent ) ? get_term( $comuna->parent, Inmo_Propiedades::TAX_UBICACION ) : null;
		$precio = get_post_meta( $id, '_inmo_precio', true );
		$moneda = get_post_meta( $id, '_inmo_moneda', true );
		$estado = get_post_meta( $id, '_inmo_estado', true );
		$fotos  = Inmo_Propiedades::galeria( $id );

		$atributos = array();
		$tarjeta   = array();
		foreach ( Inmo_Esquema::campos_para( $tipo, $op ) as $k => $c ) {
			$v = get_post_meta( $id, '_inmo_' . $k, true );
			if ( '' === $v || null === $v ) {
				continue;
			}
			$txt = self::valor_texto( $c, $v );
			if ( 'precio' !== $c['grupo'] ) {
				$atributos[ $k ] = array( 'label' => $c['label'], 'valor' => $txt, 'grupo' => $c['grupo'] );
			}
			if ( ! empty( $c['tarjeta'] ) ) {
				$tarjeta[ $k ] = isset( $c['unidad'] ) ? $txt : $txt . ' ' . ( isset( $c['corto'] ) ? $c['corto'] : strtolower( $c['label'] ) );
			}
		}
		// En la tarjeta, una sola superficie: útil para construidos, terreno para terrenos.
		if ( isset( $tarjeta['sup_util'] ) ) {
			unset( $tarjeta['sup_terreno'] );
		}

		$equip = array();
		$disp  = Inmo_Esquema::equipamiento( $tipo );
		foreach ( (array) get_post_meta( $id, '_inmo_equipamiento', true ) as $e ) {
			if ( isset( $disp[ $e ] ) ) {
				$equip[] = $disp[ $e ];
			}
		}

		$lat  = get_post_meta( $id, '_inmo_lat_pub', true );
		$lng  = get_post_meta( $id, '_inmo_lng_pub', true );
		$prec = get_post_meta( $id, '_inmo_precision', true );
		$prec = $prec ? $prec : Inmo_Ajustes::get( 'precision_defecto', 'aproximada' );

		$gc = get_post_meta( $id, '_inmo_gastos_comunes', true );

		return array(
			'id'              => $id,
			'codigo'          => get_post_meta( $id, '_inmo_codigo', true ),
			'titulo'          => get_the_title( $id ),
			'url'             => get_permalink( $id ),
			'tipo'            => $tipo,
			'tipo_label'      => $tipo ? Inmo_Esquema::TIPOS[ $tipo ] : '',
			'operacion'       => $op,
			'operacion_label' => $op ? Inmo_Esquema::OPERACIONES[ $op ] : '',
			'comuna'          => $comuna ? $comuna->name : '',
			'region'          => ( $region && ! is_wp_error( $region ) ) ? $region->name : '',
			'sector'          => get_post_meta( $id, '_inmo_sector', true ),
			'precio'          => $precio,
			'moneda'          => $moneda,
			'precio_label'    => Inmo_Esquema::etiqueta_precio( $op ),
			'precio_texto'    => Inmo_Util::formatear_monto( $precio, $moneda ),
			'equivalencia'    => self::equivalencia( $precio, $moneda ),
			'equivalencia_fuente' => (string) Inmo_Ajustes::get( 'uf_fuente' ),
			'gastos_comunes'  => '' !== (string) $gc ? Inmo_Util::formatear_monto( $gc, 'CLP' ) : '',
			'estado'          => $estado,
			'estado_label'    => isset( Inmo_Esquema::ESTADOS[ $estado ] ) ? Inmo_Esquema::ESTADOS[ $estado ] : '',
			'reservada'       => 'reservada' === $estado,
			'destacado'       => (bool) get_post_meta( $id, '_inmo_destacado', true ),
			'atributos'       => $atributos,
			'tarjeta'         => $tarjeta,
			'equipamiento'    => $equip,
			'descripcion'     => apply_filters( 'the_content', $post->post_content ),
			'fotos'           => $fotos,
			'portada'         => $fotos ? $fotos[0] : 0,
			'ubicacion'       => ( '' !== (string) $lat && '' !== (string) $lng && 'comuna' !== $prec ) ? array( 'lat' => (float) $lat, 'lng' => (float) $lng, 'precision' => $prec ) : null,
		);
	}

	public static function valor_texto( array $c, $v ) {
		if ( 'select' === $c['tipo'] ) {
			return isset( $c['opciones'][ $v ] ) ? $c['opciones'][ $v ] : $v;
		}
		if ( 'fecha' === $c['tipo'] ) {
			return Inmo_Util::fecha( $v );
		}
		if ( 'numero' === $c['tipo'] ) {
			if ( isset( $c['formato'] ) && 'clp' === $c['formato'] ) {
				return Inmo_Util::formatear_monto( $v, 'CLP' );
			}
			$f   = (float) $v;
			$dec = floor( $f ) == $f ? 0 : ( isset( $c['step'] ) && '0.5' === $c['step'] ? 1 : 2 ); // phpcs:ignore Universal.Operators.StrictComparisons
			$txt = number_format( $f, $dec, ',', '.' );
			return isset( $c['unidad'] ) ? $txt . ' ' . $c['unidad'] : $txt;
		}
		return (string) $v;
	}

	/** Equivalencia UF ↔ CLP con fuente y fecha. Devuelve '' si no hay valor UF configurado. */
	public static function equivalencia( $precio, $moneda ) {
		if ( '' === (string) $precio ) {
			return '';
		}
		$uf    = Inmo_Ajustes::uf();
		$fecha = Inmo_Util::fecha( Inmo_Ajustes::get( 'uf_fecha' ) );
		$nota  = sprintf( 'UF %s al %s', Inmo_Util::formatear_monto( $uf, 'CLP' ), $fecha );
		if ( 'UF' === $moneda ) {
			return sprintf( '≈ %s (%s)', Inmo_Util::formatear_monto( round( (float) $precio * $uf ), 'CLP' ), $nota );
		}
		return sprintf( '≈ %s (%s)', Inmo_Util::formatear_monto( round( (float) $precio / $uf, 2 ), 'UF' ), $nota );
	}
}
