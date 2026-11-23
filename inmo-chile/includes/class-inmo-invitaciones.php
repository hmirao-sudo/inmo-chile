<?php
/**
 * Invitaciones individuales con vencimiento para que el propietario complete características y fotos.
 * Solo se guarda el hash del token; el enlace se muestra/envía una única vez.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Invitaciones {

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_invitaciones';
	}

	private static function hash( $token ) {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Crea una invitación y devuelve [ 'url' => ..., 'vence' => ... ]. Anula invitaciones anteriores de la misma propiedad.
	 */
	public static function crear( $propiedad_id, $contacto_id, $dias = null ) {
		global $wpdb;
		$dias  = $dias ? (int) $dias : (int) Inmo_Ajustes::get( 'invitacion_dias', 7 );
		$token = rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' );
		$vence = gmdate( 'Y-m-d H:i:s', strtotime( Inmo_Util::ahora() ) + $dias * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::tabla() . ' SET anulada = 1 WHERE propiedad_id = %d AND usada IS NULL', $propiedad_id ) ); // phpcs:ignore
		$wpdb->insert(
			self::tabla(),
			array(
				'propiedad_id' => (int) $propiedad_id,
				'contacto_id'  => (int) $contacto_id,
				'token_hash'   => self::hash( $token ),
				'vence'        => $vence,
				'creado'       => Inmo_Util::ahora(),
				'creado_por'   => get_current_user_id(),
			)
		);
		Inmo_Historial::registrar( 'invitacion', 'Invitación para completar la ficha, vence el ' . Inmo_Util::fecha( $vence, true ), array( 'propiedad_id' => $propiedad_id, 'contacto_id' => $contacto_id ) );
		return array(
			'url'   => add_query_arg( 't', $token, self::url_pagina() ),
			'vence' => $vence,
		);
	}

	public static function url_pagina() {
		$pid = (int) get_option( 'inmo_pagina_completar' );
		return $pid ? get_permalink( $pid ) : home_url( '/completar-propiedad/' );
	}

	/**
	 * Valida un token. Devuelve la fila o WP_Error con motivo legible.
	 */
	public static function validar( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || strlen( $token ) < 20 ) {
			return new WP_Error( 'inmo_token', 'El enlace no es válido.' );
		}
		$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE token_hash = %s', self::hash( $token ) ) ); // phpcs:ignore
		if ( ! $inv || $inv->anulada ) {
			return new WP_Error( 'inmo_token', 'El enlace no es válido o fue reemplazado por uno nuevo. Pide un nuevo enlace a tu corredor.' );
		}
		if ( $inv->usada ) {
			return new WP_Error( 'inmo_usada', 'Ya recibimos la información de esta propiedad. Si necesitas cambiar algo, escríbele a tu corredor.' );
		}
		if ( strtotime( $inv->vence ) < strtotime( Inmo_Util::ahora() ) ) {
			return new WP_Error( 'inmo_vencida', 'Este enlace venció el ' . Inmo_Util::fecha( $inv->vence ) . '. Pide uno nuevo a tu corredor.' );
		}
		$estado = get_post_meta( $inv->propiedad_id, '_inmo_estado', true );
		if ( ! in_array( $estado, array( 'borrador', 'en_revision' ), true ) ) {
			return new WP_Error( 'inmo_cerrada', 'La ficha de esta propiedad ya fue revisada por la corredora. Si necesitas cambiar algo, escríbele a tu corredor.' );
		}
		return $inv;
	}

	public static function marcar_usada( $id ) {
		global $wpdb;
		$wpdb->update( self::tabla(), array( 'usada' => Inmo_Util::ahora() ), array( 'id' => (int) $id ) );
	}

	public static function vigente( $propiedad_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE propiedad_id = %d AND anulada = 0 AND usada IS NULL AND vence > %s ORDER BY id DESC LIMIT 1', $propiedad_id, Inmo_Util::ahora() ) ); // phpcs:ignore
	}

	public static function ultima( $propiedad_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE propiedad_id = %d ORDER BY id DESC LIMIT 1', $propiedad_id ) ); // phpcs:ignore
	}
}
