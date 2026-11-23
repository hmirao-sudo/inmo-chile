<?php
// Utilidad CLI para las pruebas: lee el estado real desde la base de datos (JSON).
$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
require dirname( __DIR__ ) . '/core/wp-load.php';
global $wpdb;
$cmd = $argv[1] ?? '';
$out = array();
switch ( $cmd ) {
	case 'propiedad':
		$id = Inmo_Propiedades::por_codigo( $argv[2] );
		$out = array(
			'id' => $id, 'status' => get_post_status( $id ), 'estado' => get_post_meta( $id, '_inmo_estado', true ),
			'autor' => get_userdata( get_post_field( 'post_author', $id ) )->user_login ?? '',
			'precio' => get_post_meta( $id, '_inmo_precio', true ), 'moneda' => get_post_meta( $id, '_inmo_moneda', true ),
			'galeria' => Inmo_Propiedades::galeria( $id ), 'url' => get_permalink( $id ),
			'docs' => $wpdb->get_results( $wpdb->prepare( 'SELECT id,tipo,estado,nombre FROM ' . Inmo_Documentos::tabla() . ' WHERE propiedad_id=%d', $id ) ),
			'titulares' => wp_list_pluck( Inmo_Contactos::titulares( $id ), 'nombre' ),
		);
		break;
	case 'ultima_captacion':
		$out = $wpdb->get_row( 'SELECT codigo, propiedad_id FROM ' . Inmo_Consultas::tabla() . " WHERE tipo='captacion' ORDER BY id DESC LIMIT 1" );
		break;
	case 'contacto':
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, nombre, roles FROM ' . Inmo_Contactos::tabla() . ' WHERE email=%s', $argv[2] ) );
		$out['contactos'] = $rows;
		if ( $rows ) {
			$out['consultas'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id,tipo,codigo,propiedad_id,estado,visita_fecha,corredor_id FROM ' . Inmo_Consultas::tabla() . ' WHERE contacto_id=%d ORDER BY id', $rows[0]->id ) );
		}
		break;
	case 'permisos_doc':
		$doc = Inmo_Documentos::get( (int) $argv[2] );
		foreach ( array( 'admin', 'camila.rojas', 'diego.munoz' ) as $l ) {
			$u = get_user_by( 'login', $l );
			$out[ $l ] = array(
				'puede_abrir_documento' => Inmo_Documentos::puede_abrir( $doc, $u->ID ),
				'puede_editar_propiedad' => user_can( $u->ID, 'edit_post', $doc->propiedad_id ),
			);
		}
		$out['anonimo'] = array( 'puede_abrir_documento' => Inmo_Documentos::puede_abrir( $doc, 0 ) );
		break;
	case 'descargar_como':
		// Ejecuta el endpoint real de descarga como un usuario, con un nonce válido para ese usuario.
		wp_set_current_user( get_user_by( 'login', $argv[3] )->ID );
		$_GET['id']       = (int) $argv[2];
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'inmo_documento_' . (int) $argv[2] );
		add_filter( 'wp_die_handler', static function () {
			return static function ( $msg, $title, $args ) {
				echo wp_json_encode( array( 'http' => $args['response'] ?? 500, 'mensaje' => wp_strip_all_tags( is_string( $msg ) ? $msg : '' ) ), JSON_UNESCAPED_UNICODE );
				exit;
			};
		} );
		ob_start(
			static function ( $cuerpo ) {
				if ( 0 === strpos( (string) $cuerpo, '{"http"' ) ) {
					return $cuerpo;
				}
				return wp_json_encode( array( 'http' => 200, 'bytes' => strlen( $cuerpo ), 'es_pdf' => 0 === strpos( $cuerpo, '%PDF' ) ) );
			}
		);
		Inmo_Documentos::descargar();
		exit;
	case 'nonce_doc':
		// Genera la URL firmada para un usuario (para probar el endpoint con su sesión).
		// Se usa la cookie de sesión real del navegador: el nonce de WordPress depende del token de sesión.
		$_COOKIE[ LOGGED_IN_COOKIE ] = $argv[4];
		wp_set_current_user( get_user_by( 'login', $argv[3] )->ID );
		$out = array( 'url' => Inmo_Documentos::url( (int) $argv[2] ) );
		break;
	case 'oportunidad':
		// argv[2] = email del contacto, argv[3] = código de propiedad
		$cid = Inmo_Contactos::buscar_existente( $argv[2], '' );
		$pid = Inmo_Propiedades::por_codigo( $argv[3] );
		$out = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Inmo_Oportunidades::tabla() . ' WHERE contacto_id=%d AND propiedad_id=%d ORDER BY id DESC LIMIT 1', $cid, $pid ) );
		break;
	case 'oportunidades_propiedad':
		$out = $wpdb->get_results( $wpdb->prepare( 'SELECT id, contacto_id, etapa, motivo_perdida FROM ' . Inmo_Oportunidades::tabla() . ' WHERE propiedad_id=%d ORDER BY id', Inmo_Propiedades::por_codigo( $argv[2] ) ) );
		break;
	case 'plantilla':
		$pl = Inmo_Plantillas::de_tipo( $argv[2] );
		$out = array( 'id' => $pl[0]->ID, 'version' => (int) get_post_meta( $pl[0]->ID, '_inmo_version', true ) );
		break;
	case 'emisiones':
		$rows = $wpdb->get_results( 'SELECT id, numero, tipo, plantilla_version, sha256, estado_emision, estado_firma, documento_id, html FROM ' . Inmo_Contratos::tabla() . ' ORDER BY id' );
		foreach ( $rows as $r ) {
			$e = Inmo_Contratos::get( $r->id );
			$doc = Inmo_Documentos::get( $r->documento_id );
			$r->ruta_pdf = Inmo_Documentos::ruta_absoluta( $doc );
			$r->sha_actual = $r->ruta_pdf ? hash_file( 'sha256', $r->ruta_pdf ) : '';
			$r->integro = Inmo_Contratos::integro( $e );
			$r->firmantes = $e->firmantes;
		}
		$out = $rows;
		break;
	case 'arriendo':
		$a = Inmo_Arriendos::vigente_de_propiedad( Inmo_Propiedades::por_codigo( $argv[2] ) );
		if ( $a ) {
			$a->cobros = $wpdb->get_results( $wpdb->prepare( 'SELECT id, periodo, vence, monto_renta, total, pagado, estado, nota, uf_valor FROM ' . $wpdb->prefix . 'inmo_cobros WHERE arriendo_id=%d ORDER BY periodo', $a->id ) );
			$a->liquidaciones = $wpdb->get_results( $wpdb->prepare( 'SELECT id, numero, periodo, ingresos, comision, iva, gastos, neto, estado, sha256, documento_id, detalle FROM ' . $wpdb->prefix . 'inmo_liquidaciones WHERE arriendo_id=%d ORDER BY id', $a->id ) );
			foreach ( $a->liquidaciones as $l ) {
				$d = Inmo_Documentos::get( $l->documento_id );
				$l->ruta_pdf = Inmo_Documentos::ruta_absoluta( $d );
				$l->sha_actual = $l->ruta_pdf ? hash_file( 'sha256', $l->ruta_pdf ) : '';
			}
		}
		$out = $a;
		break;
	case 'portal_puede':
		// argv[2] = email del contacto, argv[3] = id de documento
		$out = array( 'puede' => Inmo_Portal::puede_descargar( Inmo_Contactos::buscar_existente( $argv[2], '' ), (int) $argv[3] ) );
		break;
	case 'docs_propiedad':
		$out = $wpdb->get_results( $wpdb->prepare( 'SELECT id, tipo, subtipo, nombre FROM ' . Inmo_Documentos::tabla() . ' WHERE propiedad_id=%d ORDER BY id', Inmo_Propiedades::por_codigo( $argv[2] ) ) );
		break;
	case 'mensajes_portal':
		$out = $wpdb->get_results( $wpdb->prepare( 'SELECT id, tipo, codigo, mensaje, origen, corredor_id FROM ' . Inmo_Consultas::tabla() . " WHERE contacto_id=%d AND origen='portal'", Inmo_Contactos::buscar_existente( $argv[2], '' ) ) );
		break;
	case 'probar_cmf':
		// Simula la API CMF (formato documentado en api.cmfchile.cl/documentacion/UF.html) sin clave real.
		$pedidas = array();
		$modo    = $argv[2] ?? 'ok';
		add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$pedidas, $modo ) {
			if ( false === strpos( $url, 'api.cmfchile.cl' ) ) {
				return $pre;
			}
			$pedidas[] = $url;
			if ( 'clave_invalida' === $modo ) {
				return array( 'response' => array( 'code' => 421, 'message' => '' ), 'body' => wp_json_encode( array( 'CodigoHTTP' => 421, 'CodigoError' => 91, 'Mensaje' => 'API key no valida' ) ), 'headers' => array(), 'cookies' => array() );
			}
			$ufs = array();
			$d   = date_create_immutable( 'first day of last month', wp_timezone() );
			$fin = date_create_immutable( 'first day of next month', wp_timezone() )->modify( '+8 days' ); // publicada hasta el día 9 del mes siguiente
			$v   = 39000.00;
			while ( $d <= $fin ) {
				$ufs[] = array( 'Valor' => number_format( $v, 2, ',', '.' ), 'Fecha' => $d->format( 'Y-m-d' ) );
				$v    += 7.25;
				$d     = $d->modify( '+1 day' );
			}
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'UFs' => $ufs ) ), 'headers' => array(), 'cookies' => array() );
		}, 10, 3 );
		$antes = array( Inmo_Ajustes::get( 'uf_valor' ), Inmo_Ajustes::get( 'uf_fecha' ), Inmo_Ajustes::get( 'uf_fuente' ) );
		Inmo_Ajustes::guardar( array( 'uf_fuente_auto' => 'cmf', 'cmf_api_key' => 'CLAVE-DE-PRUEBA' ) );
		$r   = Inmo_Indicadores::actualizar_uf();
		$hoy = current_time( 'Y-m-d' );
		$vto = date_create_immutable( 'first day of next month', wp_timezone() )->modify( '+4 days' )->format( 'Y-m-d' );
		$out = array(
			'resultado' => is_wp_error( $r ) ? array( 'error' => $r->get_error_message() ) : $r,
			'url'       => $pedidas ? preg_replace( '/apikey=[^&]+/', 'apikey=***', $pedidas[0] ) : '',
			'antes'     => $antes,
			'vigente'   => array( Inmo_Ajustes::get( 'uf_valor' ), Inmo_Ajustes::get( 'uf_fecha' ), Inmo_Ajustes::get( 'uf_fuente' ) ),
			'hoy'       => $hoy,
			'uf_vencimiento_futuro' => Inmo_Indicadores::uf_para( $vto ),
			'vencimiento' => $vto,
		);
		break;
	case 'reset_limite':
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%inmo\\_rl\\_%'" );
		$out = array( 'ok' => true );
		break;
	case 'ajuste':
		Inmo_Ajustes::guardar( array( $argv[2] => $argv[3] ) );
		$out = array( $argv[2] => Inmo_Ajustes::get( $argv[2] ) );
		break;
	case 'valorizaciones_consulta':
		$q   = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}inmo_consultas WHERE tipo='valorizacion' ORDER BY id DESC LIMIT 1" );
		$out = $wpdb->get_results( $wpdb->prepare( "SELECT operacion, valor, minimo, maximo, moneda, confianza, n FROM {$wpdb->prefix}inmo_valorizaciones WHERE consulta_id=%d ORDER BY id", $q ) );
		break;
	case 'historial':
		$out = $wpdb->get_results( $wpdb->prepare( 'SELECT accion, detalle FROM ' . Inmo_Historial::tabla() . ' WHERE propiedad_id=%d ORDER BY id', Inmo_Propiedades::por_codigo( $argv[2] ) ) );
		break;
}
echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
