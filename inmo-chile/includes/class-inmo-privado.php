<?php
/**
 * Datos privados de la propiedad en tabla propia (fuera de postmeta):
 * dirección exacta, coordenadas exactas, rol SII, mandato, exclusividad, comisión, vencimiento y notas.
 * Así no pueden filtrarse por REST, feeds, exportadores genéricos ni consultas de metadatos.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Privado {

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_propiedad_privado';
	}

	public static function campos() {
		return array(
			'direccion'        => array( 'label' => 'Dirección exacta (privada)', 'tipo' => 'texto' ),
			'unidad'           => array( 'label' => 'Depto / oficina / casa N°', 'tipo' => 'texto' ),
			'rol_sii'          => array( 'label' => 'Rol SII', 'tipo' => 'texto' ),
			'lat'              => array( 'label' => 'Latitud exacta', 'tipo' => 'coord' ),
			'lng'              => array( 'label' => 'Longitud exacta', 'tipo' => 'coord' ),
			'mandato_tipo'     => array( 'label' => 'Tipo de mandato', 'tipo' => 'select', 'opciones' => array( '' => '— Sin mandato —', 'corretaje' => 'Corretaje', 'corretaje_admin' => 'Corretaje y administración' ) ),
			'exclusividad'     => array( 'label' => 'Exclusividad', 'tipo' => 'select', 'opciones' => array( 'no' => 'Sin exclusividad', 'si' => 'Exclusiva' ) ),
			'comision_pct'     => array( 'label' => 'Comisión (%)', 'tipo' => 'numero', 'step' => '0.01' ),
			'comision_detalle' => array( 'label' => 'Detalle de comisión', 'tipo' => 'texto' ),
			'captacion_vence'  => array( 'label' => 'Vencimiento de la captación', 'tipo' => 'fecha' ),
			'precio_minimo'    => array( 'label' => 'Precio mínimo aceptado (privado)', 'tipo' => 'numero', 'step' => '0.01' ),
			'notas'            => array( 'label' => 'Notas internas', 'tipo' => 'textarea' ),
		);
	}

	public static function get( $propiedad_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE propiedad_id = %d', $propiedad_id ), ARRAY_A ); // phpcs:ignore
		$base = array_fill_keys( array_keys( self::campos() ), '' );
		return $row ? array_merge( $base, $row ) : $base;
	}

	public static function guardar( $propiedad_id, array $datos ) {
		global $wpdb;
		$limpio = array();
		foreach ( self::campos() as $k => $c ) {
			if ( ! array_key_exists( $k, $datos ) ) {
				continue;
			}
			$v = $datos[ $k ];
			switch ( $c['tipo'] ) {
				case 'numero':
					$v = Inmo_Util::parse_numero( $v );
					$v = '' === $v ? null : $v;
					break;
				case 'coord':
					$v = is_numeric( $v ) ? round( (float) $v, 7 ) : null;
					break;
				case 'fecha':
					$v = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? $v : null;
					break;
				case 'textarea':
					$v = sanitize_textarea_field( $v );
					break;
				case 'select':
					$v = array_key_exists( $v, $c['opciones'] ) ? $v : '';
					break;
				default:
					$v = sanitize_text_field( $v );
			}
			$limpio[ $k ] = $v;
		}
		if ( ! $limpio ) {
			return;
		}
		$limpio['actualizado'] = Inmo_Util::ahora();
		$existe = $wpdb->get_var( $wpdb->prepare( 'SELECT propiedad_id FROM ' . self::tabla() . ' WHERE propiedad_id = %d', $propiedad_id ) ); // phpcs:ignore
		if ( $existe ) {
			$wpdb->update( self::tabla(), $limpio, array( 'propiedad_id' => $propiedad_id ) );
		} else {
			$limpio['propiedad_id'] = $propiedad_id;
			$wpdb->insert( self::tabla(), $limpio );
		}
	}

	public static function borrar( $propiedad_id ) {
		global $wpdb;
		$wpdb->delete( self::tabla(), array( 'propiedad_id' => (int) $propiedad_id ) );
	}
}
