<?php
/**
 * Indicadores: valor UF (manual o automático desde mindicador.cl o la API de la CMF) con historial por fecha,
 * para convertir rentas en UF con la UF del día de vencimiento y mostrar siempre fuente y fecha.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Indicadores {

	const OPCION_HIST = 'inmo_uf_historial';

	const FUENTES = array(
		'manual'     => 'Manual (Ajustes)',
		'mindicador' => 'mindicador.cl (sin clave)',
		'cmf'        => 'API CMF Chile (requiere clave gratuita)',
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

	public static function registrar_uf( $fecha, $valor, $fuente ) {
		$h            = self::historial();
		$h[ $fecha ]  = round( (float) $valor, 2 );
		ksort( $h );
		$h = array_slice( $h, -400, null, true );
		update_option( self::OPCION_HIST, $h, false );
		// El ajuste "vigente" es el más reciente.
		$ultima = array_key_last( $h );
		Inmo_Ajustes::guardar( array( 'uf_valor' => number_format( $h[ $ultima ], 2, '.', '' ), 'uf_fecha' => $ultima, 'uf_fuente' => $fuente ) );
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
	 * Descarga el valor UF del día desde la fuente configurada.
	 *
	 * @return true|WP_Error
	 */
	public static function actualizar_uf() {
		$fuente = Inmo_Ajustes::get( 'uf_fuente_auto', 'manual' );
		if ( 'manual' === $fuente ) {
			return new WP_Error( 'inmo_uf', 'La actualización automática está desactivada (fuente manual).' );
		}
		$r = apply_filters( 'inmo_uf_remota', null, $fuente );
		if ( null === $r ) {
			$r = 'cmf' === $fuente ? self::desde_cmf() : self::desde_mindicador();
		}
		if ( is_wp_error( $r ) ) {
			Inmo_Historial::registrar( 'uf', 'No se pudo actualizar la UF: ' . $r->get_error_message() );
			return $r;
		}
		self::registrar_uf( $r['fecha'], $r['valor'], $r['fuente'] );
		Inmo_Historial::registrar( 'uf', 'UF ' . Inmo_Util::formatear_monto( $r['valor'], 'CLP' ) . ' al ' . Inmo_Util::fecha( $r['fecha'] ) . ' (' . $r['fuente'] . ')' );
		return true;
	}

	private static function desde_mindicador() {
		$res = wp_remote_get( 'https://mindicador.cl/api/uf', array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $j['serie'][0]['valor'] ) ) {
			return new WP_Error( 'inmo_uf', 'Respuesta inválida de mindicador.cl (HTTP ' . wp_remote_retrieve_response_code( $res ) . ').' );
		}
		return array( 'valor' => (float) $j['serie'][0]['valor'], 'fecha' => substr( $j['serie'][0]['fecha'], 0, 10 ), 'fuente' => 'mindicador.cl (Banco Central de Chile)' );
	}

	private static function desde_cmf() {
		$key = Inmo_Ajustes::get( 'cmf_api_key' );
		if ( ! $key ) {
			return new WP_Error( 'inmo_uf', 'Falta la clave de la API CMF en Ajustes.' );
		}
		$res = wp_remote_get( 'https://api.cmfchile.cl/api-sbifv3/recursos_api/uf?apikey=' . rawurlencode( $key ) . '&formato=json', array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$j = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $j['UFs'][0]['Valor'] ) ) {
			return new WP_Error( 'inmo_uf', 'Respuesta inválida de la API CMF.' );
		}
		return array( 'valor' => (float) Inmo_Util::parse_numero( $j['UFs'][0]['Valor'] ), 'fecha' => $j['UFs'][0]['Fecha'], 'fuente' => 'API CMF Chile' );
	}
}
