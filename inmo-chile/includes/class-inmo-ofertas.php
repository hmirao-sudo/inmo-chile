<?php
/**
 * Ofertas sobre una propiedad, dentro de una oportunidad. El monto se guarda en su moneda original.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Ofertas {

	const ESTADOS = array(
		'presentada'  => 'Presentada',
		'contraoferta' => 'Contraoferta',
		'aceptada'    => 'Aceptada',
		'rechazada'   => 'Rechazada',
		'retirada'    => 'Retirada',
	);

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_ofertas';
	}

	/**
	 * @return int|WP_Error
	 */
	public static function crear( $oportunidad_id, $monto, $moneda, $condiciones = '', $vigencia = '' ) {
		global $wpdb;
		$o = Inmo_Oportunidades::get( $oportunidad_id );
		if ( ! $o || ! $o->propiedad_id ) {
			return new WP_Error( 'inmo_oferta', 'La oferta requiere una oportunidad con propiedad.' );
		}
		$monto = Inmo_Util::parse_numero( $monto );
		if ( '' === $monto || $monto <= 0 ) {
			return new WP_Error( 'inmo_oferta', 'Indica el monto de la oferta.' );
		}
		$wpdb->insert(
			self::tabla(),
			array(
				'oportunidad_id' => (int) $oportunidad_id,
				'propiedad_id'   => (int) $o->propiedad_id,
				'contacto_id'    => (int) $o->contacto_id,
				'monto'          => $monto,
				'moneda'         => 'UF' === $moneda ? 'UF' : 'CLP',
				'condiciones'    => sanitize_textarea_field( $condiciones ),
				'vigencia'       => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $vigencia ) ? $vigencia : null,
				'estado'         => 'presentada',
				'user_id'        => get_current_user_id(),
				'creado'         => Inmo_Util::ahora(),
				'actualizado'    => Inmo_Util::ahora(),
			)
		);
		$id = (int) $wpdb->insert_id;
		Inmo_Oportunidades::avanzar_a( $oportunidad_id, 'negociacion' );
		Inmo_Historial::registrar( 'oferta', 'Oferta presentada: ' . Inmo_Util::formatear_monto( $monto, 'UF' === $moneda ? 'UF' : 'CLP' ), array( 'propiedad_id' => $o->propiedad_id, 'contacto_id' => $o->contacto_id, 'oportunidad_id' => $oportunidad_id ) );
		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	public static function cambiar_estado( $id, $estado ) {
		global $wpdb;
		$f = self::get( $id );
		if ( ! $f || ! isset( self::ESTADOS[ $estado ] ) ) {
			return new WP_Error( 'inmo_oferta', 'Estado no válido.' );
		}
		$wpdb->update( self::tabla(), array( 'estado' => $estado, 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $id ) );
		if ( 'aceptada' === $estado ) {
			// El monto aceptado pasa a ser el precio acordado de la oportunidad.
			$o    = Inmo_Oportunidades::get( $f->oportunidad_id );
			$cond = $o->condiciones;
			$cond['precio_acordado'] = (float) $f->monto;
			$cond['moneda_acordada'] = $f->moneda;
			Inmo_Oportunidades::actualizar( $f->oportunidad_id, array( 'condiciones' => $cond ) );
		}
		Inmo_Historial::registrar( 'oferta', 'Oferta de ' . Inmo_Util::formatear_monto( $f->monto, $f->moneda ) . ': ' . self::ESTADOS[ $estado ], array( 'propiedad_id' => $f->propiedad_id, 'contacto_id' => $f->contacto_id, 'oportunidad_id' => $f->oportunidad_id ) );
		return true;
	}

	public static function de_oportunidad( $oportunidad_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE oportunidad_id = %d ORDER BY id DESC', $oportunidad_id ) ); // phpcs:ignore
	}

	public static function de_propiedad( $propiedad_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT f.*, c.nombre FROM ' . self::tabla() . ' f LEFT JOIN ' . Inmo_Contactos::tabla() . ' c ON c.id = f.contacto_id WHERE f.propiedad_id = %d ORDER BY f.id DESC', $propiedad_id ) ); // phpcs:ignore
	}

	public static function aceptada( $oportunidad_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . " WHERE oportunidad_id = %d AND estado = 'aceptada' ORDER BY id DESC LIMIT 1", $oportunidad_id ) ); // phpcs:ignore
	}
}
