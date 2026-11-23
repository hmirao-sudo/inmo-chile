<?php
/**
 * Utilidades: RUT, teléfonos, montos y fechas en formato chileno.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Util {

	/**
	 * Normaliza un RUT a formato 12345678-9 (sin puntos). Devuelve '' si es inválido.
	 */
	public static function normalizar_rut( $rut ) {
		$rut = strtoupper( preg_replace( '/[^0-9kK]/', '', (string) $rut ) );
		if ( strlen( $rut ) < 2 ) {
			return '';
		}
		$cuerpo = substr( $rut, 0, -1 );
		$dv     = substr( $rut, -1 );
		if ( ! ctype_digit( $cuerpo ) ) {
			return '';
		}
		return self::dv_rut( $cuerpo ) === $dv ? ltrim( $cuerpo, '0' ) . '-' . $dv : '';
	}

	/** Calcula dígito verificador (módulo 11). */
	public static function dv_rut( $cuerpo ) {
		$suma = 0;
		$mult = 2;
		for ( $i = strlen( $cuerpo ) - 1; $i >= 0; $i-- ) {
			$suma += (int) $cuerpo[ $i ] * $mult;
			$mult  = 7 === $mult ? 2 : $mult + 1;
		}
		$res = 11 - ( $suma % 11 );
		if ( 11 === $res ) {
			return '0';
		}
		if ( 10 === $res ) {
			return 'K';
		}
		return (string) $res;
	}

	/** Formatea RUT con puntos: 12.345.678-9 */
	public static function formatear_rut( $rut ) {
		$rut = self::normalizar_rut( $rut );
		if ( '' === $rut ) {
			return '';
		}
		list( $cuerpo, $dv ) = explode( '-', $rut );
		return number_format( (int) $cuerpo, 0, ',', '.' ) . '-' . $dv;
	}

	/** Enmascara un RUT para mostrarlo a quien no tiene permiso: 12.***.***-9 */
	public static function enmascarar_rut( $rut ) {
		$f = self::formatear_rut( $rut );
		return $f ? substr( $f, 0, 2 ) . '.***.***-' . substr( $f, -1 ) : '';
	}

	/** Normaliza teléfonos chilenos a +569XXXXXXXX / +562XXXXXXXX. Devuelve '' si no parece válido. */
	public static function normalizar_telefono( $tel ) {
		$d = preg_replace( '/\D/', '', (string) $tel );
		if ( 0 === strpos( $d, '56' ) && strlen( $d ) === 11 ) {
			$d = substr( $d, 2 );
		}
		if ( strlen( $d ) === 8 ) {
			$d = '9' . $d; // Celulares antiguos de 8 dígitos.
		}
		if ( strlen( $d ) !== 9 ) {
			return '';
		}
		return '+56' . $d;
	}

	public static function normalizar_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		return is_email( $email ) ? $email : '';
	}

	/** Formatea un monto en su moneda original. */
	public static function formatear_monto( $monto, $moneda ) {
		if ( '' === $monto || null === $monto ) {
			return '';
		}
		$monto = (float) $monto;
		$signo = $monto < 0 ? '-' : '';
		$monto = abs( $monto );
		if ( 'UF' === $moneda ) {
			$dec = ( floor( $monto ) == $monto ) ? 0 : 2; // phpcs:ignore Universal.Operators.StrictComparisons
			return $signo . 'UF ' . number_format( $monto, $dec, ',', '.' );
		}
		return $signo . '$' . number_format( round( $monto ), 0, ',', '.' );
	}

	/** Convierte un número en formato chileno ("5.200,5") o simple a float. */
	public static function parse_numero( $valor ) {
		$valor = trim( (string) $valor );
		if ( '' === $valor ) {
			return '';
		}
		$valor = str_replace( array( '$', 'UF', ' ' ), '', $valor );
		if ( preg_match( '/^\d{1,3}(\.\d{3})+(,\d+)?$/', $valor ) || false !== strpos( $valor, ',' ) ) {
			$valor = str_replace( '.', '', $valor );
			$valor = str_replace( ',', '.', $valor );
		}
		return is_numeric( $valor ) ? (float) $valor : '';
	}

	/** Fecha legible dd-mm-aaaa. */
	public static function fecha( $mysql_o_ts, $con_hora = false ) {
		if ( empty( $mysql_o_ts ) || '0000-00-00 00:00:00' === $mysql_o_ts ) {
			return '';
		}
		$formato = $con_hora ? 'd-m-Y H:i' : 'd-m-Y';
		if ( is_numeric( $mysql_o_ts ) ) {
			return wp_date( $formato, (int) $mysql_o_ts, wp_timezone() );
		}
		// Las fechas MySQL del plugin se guardan en hora local del sitio.
		$d = date_create_immutable( $mysql_o_ts, wp_timezone() );
		return $d ? $d->format( $formato ) : '';
	}

	/** Hora actual MySQL en la zona del sitio. */
	public static function ahora() {
		return current_time( 'mysql' );
	}

	/** Hash no reversible de la IP del cliente (para límites de envío, sin guardar la IP). */
	public static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/** Lee un valor POST saneado como texto. */
	public static function post( $clave, $defecto = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- la verificación la hace quien llama.
		return isset( $_POST[ $clave ] ) ? sanitize_text_field( wp_unslash( $_POST[ $clave ] ) ) : $defecto;
	}

	public static function post_textarea( $clave ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $clave ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $clave ] ) ) : '';
	}

	public static function get( $clave, $defecto = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $clave ] ) ? sanitize_text_field( wp_unslash( $_GET[ $clave ] ) ) : $defecto;
	}
}
