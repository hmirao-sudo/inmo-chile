<?php
/**
 * Indicadores: valor UF con historial diario, para convertir rentas en UF con la UF del día de vencimiento
 * y mostrar siempre fuente y fecha.
 *
 * Fuentes:
 *  - Manual (Ajustes).
 *  - API CMF Bancos v3 (https://api.cmfchile.cl/documentacion/UF.html): requiere API key gratuita.
 *    Se usa el recurso «UF de fechas posteriores a un mes de un año» desde el mes anterior, que trae
 *    también los días ya publicados por adelantado (la UF se conoce hasta el día 9 del mes siguiente).
 *  - mindicador.cl (sin clave, últimos 30 días; servicio comunitario sin garantía de disponibilidad).
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Indicadores {

	const OPCION_HIST = 'inmo_uf_historial';
	const CMF_BASE    = 'https://api.cmfchile.cl/api-sbifv3/recursos_api/uf';

	const FUENTES = array(
		'manual'     => 'Manual (Ajustes)',
		'cmf'        => 'API CMF Chile (recomendada; requiere clave gratuita)',
		'mindicador' => 'mindicador.cl (sin clave)',
	);

	public static function init() {
		add_action( 'inmo_diario', array( __CLASS__, 'actualizar_uf' ), 5 );
	}

	/** Historial [ 'Y-m-d' => valor ]. Se alimenta con cada valor guardado (manual o automático). */
	public static function historial() {
		$h = get_option( self::OPCION_HIST, array() );
		$h = is_array( $h ) ? $h : array();
		$f = Inmo_Ajustes::get( 'uf_fecha' );
		if ( $f && ! isset( $h[ $f ] ) ) {
			$h[ $f ] = (float) Inmo_Ajustes::get( 'uf_valor' );
		}
		ksort( $h );
		return $h;
	}

	/**
	 * Agrega valores al historial y fija como vigente el de hoy (o el último anterior a hoy).
	 *
	 * @param array  $valores [ 'Y-m-d' => float ].
	 * @param string $fuente  Texto que se muestra al público.
	 */
	public static function registrar_serie( array $valores, $fuente ) {
		$h = self::historial();
		foreach ( $valores as $f => $v ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f ) && (float) $v > 1000 ) {
				$h[ $f ] = round( (float) $v, 2 );
			}
		}
		ksort( $h );
		$h = array_slice( $h, -800, null, true );
		update_option( self::OPCION_HIST, $h, false );
		$hoy     = current_time( 'Y-m-d' );
		$vigente = null;
		foreach ( $h as $f => $v ) {
			if ( $f <= $hoy ) {
				$vigente = $f;
			}
		}
		if ( $vigente ) {
			Inmo_Ajustes::guardar( array( 'uf_valor' => number_format( $h[ $vigente ], 2, '.', '' ), 'uf_fecha' => $vigente, 'uf_fuente' => $fuente ) );
		}
		return $vigente;
	}

	public static function registrar_uf( $fecha, $valor, $fuente ) {
		return self::registrar_serie( array( $fecha => $valor ), $fuente );
	}

	/**
	 * UF aplicable a una fecha: el valor de ese día o el último anterior conocido.
	 *
	 * @return array [ 'valor' => float, 'fecha' => 'Y-m-d', 'fuente' => string, 'exacta' => bool ]
	 */
	public static function uf_para( $fecha ) {
		$h     = self::historial();
		$valor = null;
		$usada = null;
		foreach ( $h as $f => $v ) {
			if ( $f <= $fecha ) {
				$valor = $v;
				$usada = $f;
			}
		}
		if ( null === $valor ) {
			$valor = Inmo_Ajustes::uf();
			$usada = Inmo_Ajustes::get( 'uf_fecha' );
		}
		return array( 'valor' => (float) $valor, 'fecha' => $usada, 'fuente' => Inmo_Ajustes::get( 'uf_fuente' ), 'exacta' => $usada === $fecha );
	}

	/**
	 * Descarga la UF desde la fuente configurada y actualiza historial y valor vigente.
	 *
	 * @return array|WP_Error [ 'dias' => n, 'vigente' => 'Y-m-d', 'hasta' => 'Y-m-d' ]
	 */
	public static function actualizar_uf() {
		$fuente = Inmo_Ajustes::get( 'uf_fuente_auto', 'manual' );
		if ( 'manual' === $fuente ) {
			return new WP_Error( 'inmo_uf', 'La actualización automática está desactivada (fuente manual).' );
		}
		$r = 'cmf' === $fuente ? self::desde_cmf() : self::desde_mindicador();
		if ( is_wp_error( $r ) ) {
			Inmo_Historial::registrar( 'uf', 'No se pudo actualizar la UF (' . self::FUENTES[ $fuente ] . '): ' . $r->get_error_message() );
			return $r;
		}
		$vigente = self::registrar_serie( $r['valores'], $r['fuente'] );
		ksort( $r['valores'] );
		$hasta = array_key_last( $r['valores'] );
		Inmo_Historial::registrar( 'uf', sprintf( 'UF actualizada desde %s: %d día(s), vigente %s al %s, publicada hasta el %s', $r['fuente'], count( $r['valores'] ), Inmo_Util::formatear_monto( Inmo_Ajustes::get( 'uf_valor' ), 'CLP' ), Inmo_Util::fecha( $vigente ), Inmo_Util::fecha( $hasta ) ) );
		return array( 'dias' => count( $r['valores'] ), 'vigente' => $vigente, 'hasta' => $hasta );
	}

	/** Convierte «39.512,34» (formato CMF) a float. */
	public static function numero_cmf( $s ) {
		return (float) str_replace( array( '.', ',' ), array( '', '.' ), trim( (string) $s ) );
	}

	/** URL del recurso CMF «posteriores» al mes indicado (sin la clave). */
	public static function url_cmf( $anio, $mes ) {
		return self::CMF_BASE . '/posteriores/' . (int) $anio . '/' . str_pad( (string) (int) $mes, 2, '0', STR_PAD_LEFT );
	}

	private static function desde_cmf() {
		$key = trim( (string) Inmo_Ajustes::get( 'cmf_api_key' ) );
		if ( ! $key ) {
			return new WP_Error( 'inmo_uf', 'Falta la API key de la CMF en Ajustes (se solicita gratis en api.cmfchile.cl).' );
		}
		// Desde el mes anterior: cubre el mes en curso y los días ya publicados del siguiente.
		$ref = date_create_immutable( 'first day of last month', wp_timezone() );
		$url = add_query_arg( array( 'apikey' => rawurlencode( $key ), 'formato' => 'json' ), self::url_cmf( $ref->format( 'Y' ), $ref->format( 'n' ) ) );
		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$codigo = (int) wp_remote_retrieve_response_code( $res );
		$j      = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( isset( $j['CodigoHTTP'] ) || isset( $j['Mensaje'] ) ) {
			// Formato de error de la API: { CodigoHTTP, CodigoError, Mensaje }.
			return new WP_Error( 'inmo_uf', 'CMF: ' . ( $j['Mensaje'] ?? 'error' ) . ' (código ' . ( $j['CodigoError'] ?? $codigo ) . ').' );
		}
		if ( 200 !== $codigo || empty( $j['UFs'] ) || ! is_array( $j['UFs'] ) ) {
			return new WP_Error( 'inmo_uf', 'Respuesta inesperada de la API CMF (HTTP ' . $codigo . ').' );
		}
		$valores = array();
		foreach ( $j['UFs'] as $uf ) {
			if ( ! empty( $uf['Fecha'] ) && isset( $uf['Valor'] ) ) {
				$valores[ substr( $uf['Fecha'], 0, 10 ) ] = self::numero_cmf( $uf['Valor'] );
			}
		}
		if ( ! $valores ) {
			return new WP_Error( 'inmo_uf', 'La API CMF no entregó valores.' );
		}
		return array( 'valores' => $valores, 'fuente' => 'CMF Chile (API Bancos v3)' );
	}

	private static function desde_mindicador() {
		$res = wp_remote_get( 'https://mindicador.cl/api/uf', array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || empty( $j['serie'] ) ) {
			return new WP_Error( 'inmo_uf', 'Respuesta inválida de mindicador.cl (HTTP ' . wp_remote_retrieve_response_code( $res ) . ').' );
		}
		$valores = array();
		foreach ( $j['serie'] as $d ) {
			$valores[ substr( $d['fecha'], 0, 10 ) ] = (float) $d['valor'];
		}
		return array( 'valores' => $valores, 'fuente' => 'mindicador.cl (Banco Central de Chile)' );
	}
}
