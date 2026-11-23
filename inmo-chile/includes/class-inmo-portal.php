<?php
/**
 * Portal privado del dueño: cada titular entra con su propia cuenta (rol inmo_propietario) y ve solo
 * sus propiedades: estado y publicación, consultas y visitas (sin datos de los interesados), ofertas,
 * documentos en que es parte, arriendo administrado, cobros y liquidaciones. Puede escribir a su corredor.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Portal {

	const ROL = 'inmo_propietario';

	public static function init() {
		add_shortcode( 'inmo_portal_propietario', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_inmo_portal_doc', array( __CLASS__, 'descargar' ) );
		add_action( 'admin_post_inmo_portal_mensaje', array( __CLASS__, 'mensaje' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'redirigir_login' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'bloquear_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'barra' ) );
		add_action( 'init', array( __CLASS__, 'registrar_rol' ) );
		add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'correo_reset' ), 10, 4 );
		add_filter( 'authenticate', array( __CLASS__, 'bloquear_revocados' ), 100 );
		add_action( 'template_redirect', array( __CLASS__, 'sin_cache' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/** Un acceso revocado no puede iniciar sesión. */
	public static function bloquear_revocados( $user ) {
		if ( $user instanceof WP_User && self::es_propietario( $user ) && self::revocado( $user->ID ) ) {
			return new WP_Error( 'inmo_revocado', 'Tu acceso al portal de propietarios fue desactivado. Escríbele a tu corredor.' );
		}
		return $user;
	}

	private static function es_pagina_portal() {
		$id = (int) get_option( 'inmo_pagina_portal' );
		return $id && is_page( $id );
	}

	public static function sin_cache() {
		if ( self::es_pagina_portal() ) {
			nocache_headers();
		}
	}

	public static function robots( $r ) {
		if ( self::es_pagina_portal() ) {
			$r['noindex']  = true;
			$r['nofollow'] = true;
		}
		return $r;
	}

	public static function registrar_rol() {
		if ( ! get_role( self::ROL ) ) {
			add_role( self::ROL, 'Propietario/a (portal)', array( 'read' => true ) );
		}
	}

	public static function url() {
		$id = (int) get_option( 'inmo_pagina_portal' );
		return $id ? get_permalink( $id ) : home_url( '/portal-propietario/' );
	}

	public static function es_propietario( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && $user->exists() && in_array( self::ROL, (array) $user->roles, true );
	}

	/** Contacto del CRM vinculado al usuario actual. */
	public static function contacto_actual() {
		$u = wp_get_current_user();
		if ( ! self::es_propietario( $u ) ) {
			return null;
		}
		$c = Inmo_Contactos::get( (int) get_user_meta( $u->ID, '_inmo_contacto_id', true ) );
		return ( $c && (int) self::usuario_de( $c->id ) === (int) $u->ID ) ? $c : null;
	}

	public static function usuario_de( $contacto_id ) {
		$u = get_users( array( 'meta_key' => '_inmo_contacto_id', 'meta_value' => (int) $contacto_id, 'role' => self::ROL, 'number' => 1, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $u ? (int) $u[0] : 0;
	}

	/* =====================================================================
	 * Acceso: invitar / revocar (desde la ficha del contacto)
	 * =================================================================== */

	/**
	 * Crea (o reactiva) la cuenta del propietario y devuelve el enlace para crear su contraseña.
	 *
	 * @return array|WP_Error [ 'url' => ..., 'login' => ..., 'enviado' => bool ]
	 */
	public static function invitar( $contacto_id ) {
		$c = Inmo_Contactos::get( $contacto_id );
		if ( ! $c || ! $c->email ) {
			return new WP_Error( 'inmo_portal', 'El contacto necesita un correo para acceder al portal.' );
		}
		if ( ! Inmo_Contactos::propiedades_titular( $contacto_id ) ) {
			return new WP_Error( 'inmo_portal', 'El contacto no es titular de ninguna propiedad.' );
		}
		$uid = self::usuario_de( $contacto_id );
		if ( ! $uid ) {
			$existente = get_user_by( 'email', $c->email );
			if ( $existente ) {
				if ( array_diff( (array) $existente->roles, array( self::ROL, 'subscriber' ) ) ) {
					return new WP_Error( 'inmo_portal', 'Ese correo ya pertenece a un usuario interno; usa otro correo para el portal.' );
				}
				$uid = $existente->ID;
				$existente->set_role( self::ROL );
			} else {
				$uid = wp_insert_user(
					array(
						'user_login'   => sanitize_user( current( explode( '@', $c->email ) ) . '-' . $contacto_id, true ),
						'user_email'   => $c->email,
						'user_pass'    => wp_generate_password( 32, true, true ),
						'display_name' => $c->nombre,
						'role'         => self::ROL,
					)
				);
				if ( is_wp_error( $uid ) ) {
					return $uid;
				}
			}
			update_user_meta( $uid, '_inmo_contacto_id', (int) $contacto_id );
			update_user_meta( $uid, 'show_admin_bar_front', 'false' );
		}
		delete_user_meta( $uid, '_inmo_portal_revocado' );
		$user = get_userdata( $uid );
		$key  = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$url = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$ok  = Inmo_Correo::enviar_simple(
			$c->email,
			'Tu acceso al portal de propietarios',
			"Hola {$c->nombre}:\n\nTe damos acceso al portal de propietarios, donde puedes ver el estado de tus propiedades, visitas, ofertas, documentos y liquidaciones.\n\n1. Crea tu contraseña con este enlace personal:\n{$url}\n\n2. Luego entra siempre en:\n" . self::url() . "\n\nTu usuario es: {$user->user_login}\nSi no solicitaste este acceso, ignora este correo.",
			array( 'contacto_id' => $contacto_id )
		);
		Inmo_Historial::registrar( 'contacto', 'Acceso al portal de propietarios otorgado (usuario ' . $user->user_login . ')', array( 'contacto_id' => $contacto_id ) );
		return array( 'url' => $url, 'login' => $user->user_login, 'enviado' => $ok );
	}

	public static function revocar( $contacto_id ) {
		$uid = self::usuario_de( $contacto_id );
		if ( ! $uid ) {
			return new WP_Error( 'inmo_portal', 'El contacto no tiene acceso al portal.' );
		}
		update_user_meta( $uid, '_inmo_portal_revocado', 1 );
		WP_Session_Tokens::get_instance( $uid )->destroy_all();
		Inmo_Historial::registrar( 'contacto', 'Acceso al portal revocado', array( 'contacto_id' => $contacto_id ) );
		return true;
	}

	public static function revocado( $uid ) {
		return (bool) get_user_meta( $uid, '_inmo_portal_revocado', true );
	}

	/* =====================================================================
	 * Integración con el inicio de sesión de WordPress
	 * =================================================================== */

	public static function redirigir_login( $to, $requested, $user ) {
		return ( $user instanceof WP_User && self::es_propietario( $user ) ) ? self::url() : $to;
	}

	/** Los propietarios no entran al escritorio de WordPress (salvo admin-post/ajax del portal). */
	public static function bloquear_admin() {
		if ( wp_doing_ajax() || ! self::es_propietario() ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function barra( $mostrar ) {
		return self::es_propietario() ? false : $mostrar;
	}

	/** Correo de «olvidé mi contraseña» para propietarios: en español y apuntando al portal. */
	public static function correo_reset( $email, $key, $login, $user ) {
		if ( self::es_propietario( $user ) ) {
			$email['subject'] = '[' . Inmo_Ajustes::get( 'marca_nombre' ) . '] Restablece tu contraseña del portal';
			$email['message'] = "Hola {$user->display_name}:\n\nPara crear una nueva contraseña del portal de propietarios, abre este enlace:\n" . network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $login ), 'login' ) . "\n\nLuego entra en: " . self::url();
		}
		return $email;
	}

	/* =====================================================================
	 * Datos visibles para el propietario
	 * =================================================================== */

	public static function propiedades( $contacto_id ) {
		return array_values( array_filter( array_map( 'intval', Inmo_Contactos::propiedades_titular( $contacto_id ) ), static function ( $pid ) { return INMO_CPT === get_post_type( $pid ) && 'trash' !== get_post_status( $pid ); } ) );
	}

	/** Documentos de la propiedad que el propietario puede descargar. */
	public static function documentos( $contacto_id, $pid ) {
		$out = array();
		foreach ( Inmo_Documentos::de_propiedad( $pid ) as $d ) {
			$permitido = in_array( $d->tipo, array( 'mandato', 'antecedente', 'escritura' ), true )
				|| ( 'otro' === $d->tipo && 'respaldo_gasto' === $d->subtipo )
				|| ( in_array( $d->tipo, array( 'contrato', 'firmado' ), true ) && in_array( $d->subtipo, array( 'mandato', 'arriendo', 'promesa' ), true ) )
				|| ( 'contrato' === $d->tipo && 'liquidacion' === $d->subtipo && (int) $d->contacto_id === (int) $contacto_id );
			if ( $permitido ) {
				$out[ $d->id ] = $d;
			}
		}
		return $out;
	}

	public static function puede_descargar( $contacto_id, $doc_id ) {
		$doc = Inmo_Documentos::get( $doc_id );
		if ( ! $doc || ! in_array( (int) $doc->propiedad_id, self::propiedades( $contacto_id ), true ) ) {
			return false;
		}
		return isset( self::documentos( $contacto_id, (int) $doc->propiedad_id )[ (int) $doc_id ] );
	}

	public static function url_doc( $doc_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=inmo_portal_doc&id=' . (int) $doc_id ), 'inmo_portal_doc_' . (int) $doc_id );
	}

	public static function descargar() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'inmo_portal_doc_' . $id );
		$c = self::contacto_actual();
		if ( ! $c || self::revocado( get_current_user_id() ) || ! self::puede_descargar( $c->id, $id ) ) {
			wp_die( 'No tienes permiso para abrir este documento.', 'Acceso denegado', array( 'response' => 403 ) );
		}
		$doc = Inmo_Documentos::get( $id );
		$abs = Inmo_Documentos::ruta_absoluta( $doc );
		if ( ! $abs ) {
			wp_die( 'Archivo no encontrado.', '', array( 'response' => 404 ) );
		}
		Inmo_Historial::registrar( 'documento', 'El propietario descargó ' . $doc->nombre . ' desde el portal', array( 'propiedad_id' => $doc->propiedad_id, 'contacto_id' => $c->id ) );
		nocache_headers();
		header( 'Content-Type: ' . $doc->mime );
		header( 'Content-Length: ' . filesize( $abs ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $doc->nombre ) . '"' );
		readfile( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function mensaje() {
		check_admin_referer( 'inmo_portal_mensaje' );
		$c = self::contacto_actual();
		if ( ! $c || self::revocado( get_current_user_id() ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		$pid  = absint( Inmo_Util::post( 'propiedad' ) );
		$pid  = in_array( $pid, self::propiedades( $c->id ), true ) ? $pid : 0;
		$txt  = Inmo_Util::post_textarea( 'mensaje' );
		$back = add_query_arg( $pid ? array( 'propiedad' => $pid ) : array(), self::url() );
		if ( mb_strlen( trim( $txt ) ) < 3 ) {
			wp_safe_redirect( add_query_arg( 'msj', 'vacio', $back ) . '#mensaje' );
			exit;
		}
		global $wpdb;
		$wpdb->insert(
			Inmo_Consultas::tabla(),
			array(
				'tipo'         => 'general',
				'contacto_id'  => $c->id,
				'propiedad_id' => 0, // No crea oportunidad: es un mensaje del dueño.
				'codigo'       => $pid ? get_post_meta( $pid, '_inmo_codigo', true ) : '',
				'mensaje'      => '[Portal del propietario] ' . $txt,
				'datos'        => wp_json_encode( array( 'portal' => true, 'propiedad_id' => $pid ) ),
				'estado'       => 'nueva',
				'corredor_id'  => $pid ? (int) get_post_field( 'post_author', $pid ) : ( $c->corredor_id ? (int) $c->corredor_id : Inmo_Ajustes::corredor_defecto() ),
				'origen'       => 'portal',
				'creado'       => Inmo_Util::ahora(),
				'actualizado'  => Inmo_Util::ahora(),
			)
		);
		$qid = (int) $wpdb->insert_id;
		Inmo_Historial::registrar( 'consulta_web', 'Mensaje del propietario desde el portal', array( 'propiedad_id' => $pid, 'contacto_id' => $c->id, 'consulta_id' => $qid ) );
		$corr = get_userdata( $pid ? (int) get_post_field( 'post_author', $pid ) : Inmo_Ajustes::corredor_defecto() );
		Inmo_Correo::enviar_simple( $corr ? $corr->user_email : Inmo_Ajustes::email_avisos(), 'Mensaje de ' . $c->nombre . ' (portal de propietarios)', $txt . "\n\nVer en el CRM: " . admin_url( 'admin.php?page=inmo-contactos&contacto=' . $c->id ), array( 'contacto_id' => $c->id, 'consulta_id' => $qid ) );
		wp_safe_redirect( add_query_arg( 'msj', 'ok', $back ) . '#mensaje' );
		exit;
	}

	/* =====================================================================
	 * Render del portal
	 * =================================================================== */

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return self::login();
		}
		$c = self::contacto_actual();
		if ( ! $c ) {
			if ( current_user_can( 'inmo_gestionar' ) ) {
				return '<div class="inmo-alerta inmo-alerta--error"><p>Esta página es para propietarios. Para ver lo que ve un propietario, dale acceso desde su ficha de contacto y entra con su cuenta en otra ventana privada.</p></div>';
			}
			return '<div class="inmo-alerta inmo-alerta--error"><p>Tu cuenta no tiene acceso al portal de propietarios.</p></div>';
		}
		if ( self::revocado( get_current_user_id() ) ) {
			return '<div class="inmo-alerta inmo-alerta--error"><p>Tu acceso al portal fue desactivado. Escríbele a tu corredor si necesitas recuperarlo.</p></div>';
		}
		$props = self::propiedades( $c->id );
		$sel   = absint( Inmo_Util::get( 'propiedad' ) );
		$h     = '<div class="inmo-portal">';
		$h    .= '<p class="inmo-portal__hola">Hola, <strong>' . esc_html( $c->nombre ) . '</strong> · <a href="' . esc_url( wp_logout_url( self::url() ) ) . '">Cerrar sesión</a></p>';
		if ( $sel && in_array( $sel, $props, true ) ) {
			$h .= self::detalle( $c, $sel );
		} else {
			$h .= self::resumen( $c, $props );
		}
		$h .= self::form_mensaje( $sel && in_array( $sel, $props, true ) ? $sel : 0 );
		return $h . '</div>';
	}

	private static function login() {
		$h  = '<div class="inmo-portal inmo-portal--login"><p>Ingresa con el usuario y la contraseña de tu acceso de propietario. Si aún no tienes acceso, pídeselo a tu corredor.</p>';
		$h .= wp_login_form(
			array(
				'echo'           => false,
				'redirect'       => self::url(),
				'label_username' => 'Usuario o correo',
				'label_password' => 'Contraseña',
				'label_remember' => 'Recordarme en este equipo',
				'label_log_in'   => 'Ingresar',
				'form_id'        => 'inmo-portal-login',
			)
		);
		$h .= '<p><a href="' . esc_url( wp_lostpassword_url( self::url() ) ) . '">¿Olvidaste tu contraseña?</a></p></div>';
		return $h;
	}

	private static function estado_publicacion( $pid ) {
		return 'publish' === get_post_status( $pid ) ? 'Publicada en el sitio' : 'No publicada';
	}

	private static function resumen( $c, array $props ) {
		$h = '<h2>Mis propiedades</h2>';
		if ( ! $props ) {
			return $h . '<p>Aún no tienes propiedades asociadas.</p>';
		}
		$h .= '<div class="inmo-portal__grilla">';
		foreach ( $props as $pid ) {
			$g     = Inmo_Propiedades::galeria( $pid );
			$est   = get_post_meta( $pid, '_inmo_estado', true );
			$q     = Inmo_Consultas::de_propiedad( $pid );
			$vis   = count( array_filter( $q, static function ( $x ) { return 'visita' === $x->tipo; } ) );
			$cons  = count( array_filter( $q, static function ( $x ) { return 'consulta' === $x->tipo; } ) );
			$ofs   = Inmo_Ofertas::de_propiedad( $pid );
			$arr   = Inmo_Arriendos::vigente_de_propiedad( $pid );
			$h    .= '<article class="inmo-portal__prop">' . ( $g ? wp_get_attachment_image( $g[0], 'medium', false, array( 'alt' => '' ) ) : '' );
			$h    .= '<h3><a href="' . esc_url( add_query_arg( 'propiedad', $pid, self::url() ) ) . '">' . esc_html( get_post_meta( $pid, '_inmo_codigo', true ) . ' · ' . get_post_field( 'post_title', $pid ) ) . '</a></h3>';
			$h    .= '<p><span class="inmo-portal__estado inmo-portal__estado--' . esc_attr( $est ) . '">' . esc_html( Inmo_Esquema::ESTADOS[ $est ] ?? '' ) . '</span> · ' . esc_html( self::estado_publicacion( $pid ) ) . '</p>';
			$h    .= '<ul class="inmo-portal__cifras"><li><strong>' . (int) $cons . '</strong> consultas</li><li><strong>' . (int) $vis . '</strong> visitas</li><li><strong>' . count( $ofs ) . '</strong> ofertas</li></ul>';
			if ( $arr ) {
				$h .= '<p>Arriendo administrado · renta ' . esc_html( Inmo_Util::formatear_monto( $arr->renta, $arr->moneda ) ) . '</p>';
			}
			$h .= '<p><a class="inmo-boton" href="' . esc_url( add_query_arg( 'propiedad', $pid, self::url() ) ) . '">Ver detalle</a></p></article>';
		}
		$h .= '</div>';

		$liqs = array();
		foreach ( Inmo_Arriendos::de_beneficiario( $c->id ) as $a ) {
			foreach ( Inmo_Liquidaciones::de_arriendo( $a->id ) as $l ) {
				if ( 'anulada' !== $l->estado ) {
					$liqs[] = $l;
				}
			}
		}
		if ( $liqs ) {
			$h .= '<h2>Últimas liquidaciones</h2>' . self::tabla_liquidaciones( array_slice( $liqs, 0, 6 ) );
		}
		return $h;
	}

	private static function tabla_liquidaciones( array $liqs ) {
		$h = '<div class="inmo-portal__tabla"><table><thead><tr><th scope="col">N°</th><th scope="col">Período</th><th scope="col">Recaudado</th><th scope="col">Descuentos</th><th scope="col">A transferir</th><th scope="col">Estado</th><th scope="col">PDF</th></tr></thead><tbody>';
		foreach ( $liqs as $l ) {
			$h .= '<tr><td data-label="N°">' . esc_html( $l->numero ) . '</td><td data-label="Período">' . esc_html( Inmo_Arriendos::periodo_texto( $l->periodo ) ) . '</td><td data-label="Recaudado">' . esc_html( Inmo_Util::formatear_monto( $l->ingresos, 'CLP' ) ) . '</td><td data-label="Descuentos">' . esc_html( Inmo_Util::formatear_monto( -( $l->comision + $l->iva + $l->gastos ), 'CLP' ) ) . '</td>';
			$h .= '<td data-label="A transferir"><strong>' . esc_html( Inmo_Util::formatear_monto( $l->neto, 'CLP' ) ) . '</strong></td><td data-label="Estado">' . esc_html( 'pagada' === $l->estado ? 'Transferida el ' . Inmo_Util::fecha( $l->pagada_fecha ) : 'Por transferir' ) . '</td>';
			$h .= '<td data-label="PDF"><a href="' . esc_url( self::url_doc( $l->documento_id ) ) . '">Descargar<span class="sr"> liquidación ' . esc_html( $l->numero ) . '</span></a></td></tr>';
		}
		return $h . '</tbody></table></div>';
	}

	private static function detalle( $c, $pid ) {
		$est = get_post_meta( $pid, '_inmo_estado', true );
		$h   = '<p><a href="' . esc_url( self::url() ) . '">← Mis propiedades</a></p>';
		$h  .= '<h2>' . esc_html( get_post_meta( $pid, '_inmo_codigo', true ) . ' · ' . get_post_field( 'post_title', $pid ) ) . '</h2>';
		$corr = get_userdata( (int) get_post_field( 'post_author', $pid ) );
		$h   .= '<ul class="inmo-portal__datos"><li><span>Estado</span> ' . esc_html( Inmo_Esquema::ESTADOS[ $est ] ?? '' ) . '</li>';
		$h   .= '<li><span>Precio publicado</span> ' . esc_html( Inmo_Util::formatear_monto( get_post_meta( $pid, '_inmo_precio', true ), get_post_meta( $pid, '_inmo_moneda', true ) ) ) . '</li>';
		$h   .= '<li><span>Publicación</span> ' . esc_html( self::estado_publicacion( $pid ) ) . ( 'publish' === get_post_status( $pid ) ? ' · <a href="' . esc_url( get_permalink( $pid ) ) . '" target="_blank" rel="noopener">ver aviso</a>' : '' ) . '</li>';
		$h   .= '<li><span>Tu corredor</span> ' . esc_html( $corr ? $corr->display_name : '' ) . '</li></ul>';

		// Actividad comercial sin datos de los interesados.
		$q   = Inmo_Consultas::de_propiedad( $pid );
		$h  .= '<h3>Consultas y visitas</h3>';
		$vis = array_values( array_filter( $q, static function ( $x ) { return 'visita' === $x->tipo; } ) );
		$h  .= '<p>' . count( array_filter( $q, static function ( $x ) { return 'consulta' === $x->tipo; } ) ) . ' consulta(s) y ' . count( $vis ) . ' solicitud(es) de visita. Por privacidad no mostramos los datos de las personas interesadas.</p>';
		if ( $vis ) {
			$h .= '<div class="inmo-portal__tabla"><table><thead><tr><th scope="col">Solicitada</th><th scope="col">Cita</th><th scope="col">Estado</th></tr></thead><tbody>';
			foreach ( $vis as $v ) {
				$h .= '<tr><td data-label="Solicitada">' . esc_html( Inmo_Util::fecha( $v->creado ) ) . '</td><td data-label="Cita">' . esc_html( $v->visita_fecha ? Inmo_Util::fecha( $v->visita_fecha, true ) : 'Por coordinar' ) . '</td><td data-label="Estado">' . esc_html( Inmo_Consultas::etiqueta_estado( $v ) ) . '</td></tr>';
			}
			$h .= '</tbody></table></div>';
		}
		$ofs = Inmo_Ofertas::de_propiedad( $pid );
		if ( $ofs ) {
			$h .= '<h3>Ofertas</h3><div class="inmo-portal__tabla"><table><thead><tr><th scope="col">Fecha</th><th scope="col">Monto</th><th scope="col">Estado</th></tr></thead><tbody>';
			foreach ( $ofs as $f ) {
				$h .= '<tr><td data-label="Fecha">' . esc_html( Inmo_Util::fecha( $f->creado ) ) . '</td><td data-label="Monto"><strong>' . esc_html( Inmo_Util::formatear_monto( $f->monto, $f->moneda ) ) . '</strong></td><td data-label="Estado">' . esc_html( Inmo_Ofertas::ESTADOS[ $f->estado ] ) . '</td></tr>';
			}
			$h .= '</tbody></table></div>';
		}

		// Arriendo administrado.
		$a = Inmo_Arriendos::vigente_de_propiedad( $pid );
		if ( ! $a ) {
			$todos = Inmo_Arriendos::de_propiedad( $pid );
			$a     = $todos ? $todos[0] : null;
		}
		if ( $a && (int) $a->beneficiario_id === (int) $c->id ) {
			$arr = Inmo_Contactos::get( $a->arrendatario_id );
			$h  .= '<h3>Arriendo</h3><ul class="inmo-portal__datos"><li><span>Arrendatario</span> ' . esc_html( $arr ? $arr->nombre : '' ) . '</li><li><span>Renta</span> ' . esc_html( Inmo_Util::formatear_monto( $a->renta, $a->moneda ) ) . ' · pago el día ' . (int) $a->dia_pago . '</li>';
			$h  .= '<li><span>Vigencia</span> ' . esc_html( Inmo_Util::fecha( $a->fecha_inicio ) . ' – ' . ( $a->fecha_termino ? Inmo_Util::fecha( $a->fecha_termino ) : 'indefinido' ) ) . '</li><li><span>Estado</span> ' . esc_html( Inmo_Arriendos::ESTADOS[ $a->estado ] ) . '</li></ul>';
			$cobros = Inmo_Arriendos::cobros_de( $a->id );
			if ( $cobros ) {
				$h .= '<h4>Pagos del arrendatario</h4><div class="inmo-portal__tabla"><table id="inmo-portal-cobros"><thead><tr><th scope="col">Mes</th><th scope="col">Vence</th><th scope="col">Monto</th><th scope="col">Estado</th></tr></thead><tbody>';
				foreach ( $cobros as $k ) {
					if ( 'anulado' === $k->estado ) {
						continue;
					}
					$h .= '<tr><td data-label="Mes">' . esc_html( Inmo_Arriendos::periodo_texto( $k->periodo ) ) . '</td><td data-label="Vence">' . esc_html( Inmo_Util::fecha( $k->vence ) ) . '</td><td data-label="Monto">' . esc_html( Inmo_Util::formatear_monto( $k->total, 'CLP' ) ) . '</td><td data-label="Estado">' . esc_html( Inmo_Arriendos::ESTADOS_COBRO[ $k->estado ] ) . '</td></tr>';
				}
				$h .= '</tbody></table></div>';
			}
			$liqs = array_filter( Inmo_Liquidaciones::de_arriendo( $a->id ), static function ( $l ) { return 'anulada' !== $l->estado; } );
			$h   .= '<h4>Liquidaciones</h4>' . ( $liqs ? self::tabla_liquidaciones( $liqs ) : '<p>Aún no hay liquidaciones.</p>' );
		}

		// Documentos.
		$docs = self::documentos( $c->id, $pid );
		$h   .= '<h3>Documentos</h3>';
		if ( $docs ) {
			$h .= '<ul class="inmo-portal__docs">';
			foreach ( $docs as $d ) {
				$etq = Inmo_Documentos::TIPOS[ $d->tipo ] ?? $d->tipo;
				if ( 'liquidacion' === $d->subtipo ) {
					$etq = 'Liquidación';
				} elseif ( 'respaldo_gasto' === $d->subtipo ) {
					$etq = 'Respaldo de gasto';
				} elseif ( in_array( $d->tipo, array( 'contrato', 'firmado' ), true ) ) {
					$etq = ( Inmo_Plantillas::TIPOS[ $d->subtipo ] ?? $etq ) . ( 'firmado' === $d->tipo ? ' (firmado)' : '' );
				}
				$h .= '<li><a href="' . esc_url( self::url_doc( $d->id ) ) . '">' . esc_html( $etq . ' · ' . $d->nombre ) . '</a> <span class="inmo-ayuda">' . esc_html( Inmo_Util::fecha( $d->creado ) ) . '</span></li>';
			}
			$h .= '</ul>';
		} else {
			$h .= '<p>Aún no hay documentos.</p>';
		}
		return $h;
	}

	private static function form_mensaje( $pid ) {
		$estado = Inmo_Util::get( 'msj' );
		$h      = '<section class="inmo-portal__mensaje" id="mensaje"><h2>Escribir a mi corredor</h2>';
		if ( 'ok' === $estado ) {
			$h .= '<div class="inmo-alerta inmo-alerta--ok" role="status"><p>Enviamos tu mensaje. Tu corredor te responderá dentro de ' . esc_html( Inmo_Ajustes::get( 'respuesta_plazo' ) ) . '.</p></div>';
		} elseif ( 'vacio' === $estado ) {
			$h .= '<div class="inmo-alerta inmo-alerta--error" role="alert"><p>Escribe tu mensaje.</p></div>';
		}
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="inmo-form"><input type="hidden" name="action" value="inmo_portal_mensaje"><input type="hidden" name="propiedad" value="' . (int) $pid . '">' . wp_nonce_field( 'inmo_portal_mensaje', '_wpnonce', true, false );
		$h .= '<div class="inmo-campo"><label for="portal-mensaje">Mensaje' . ( $pid ? ' sobre ' . esc_html( get_post_meta( $pid, '_inmo_codigo', true ) ) : '' ) . '</label><textarea id="portal-mensaje" name="mensaje" rows="4" required></textarea>';
		$h .= '<p class="inmo-ayuda">Para cambiar tus datos bancarios, escríbenos aquí: por seguridad los confirmamos contigo por teléfono.</p></div>';
		return $h . '<button type="submit" class="inmo-boton">Enviar</button></form></section>';
	}
}
