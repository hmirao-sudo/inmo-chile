<?php
/**
 * Endurecimiento de privacidad: superficies públicas (REST, feeds, sitemaps, adjuntos)
 * y derechos ARCO / portabilidad (exportador y borrador de datos personales de WordPress).
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Privacidad {

	public static function init() {
		// Sin sitemap de usuarios: evita exponer nombres de usuario de corredores.
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sin_sitemap_usuarios' ), 10, 2 );
		// Sin archivos de autor para propiedades (el corredor responsable es un dato interno).
		add_action( 'template_redirect', array( __CLASS__, 'bloquear_autor' ) );
		// Feeds de propiedades: solo extracto público, nunca contenido adicional.
		add_filter( 'the_content_feed', array( __CLASS__, 'feed' ) );
		add_filter( 'the_author', array( __CLASS__, 'autor_feed' ) );
		// Adjuntos: no exponer metadatos de autor en el REST de medios para visitantes.
		add_filter( 'rest_prepare_attachment', array( __CLASS__, 'rest_adjunto' ), 10, 3 );
		// La página de invitación (lleva un token en la URL) no se indexa ni filtra el token por Referer.
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_head', array( __CLASS__, 'referrer' ), 1 );
		// Derechos del titular de datos.
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'registrar_exportador' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'registrar_borrador' ) );
	}

	private static function es_pagina_invitacion() {
		$id = (int) get_option( 'inmo_pagina_completar' );
		return $id && is_page( $id );
	}

	public static function robots( $robots ) {
		if ( self::es_pagina_invitacion() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function referrer() {
		if ( self::es_pagina_invitacion() ) {
			echo '<meta name="referrer" content="no-referrer">' . "\n";
		}
	}

	public static function sin_sitemap_usuarios( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	public static function bloquear_autor() {
		if ( is_author() && ! current_user_can( 'inmo_gestionar' ) ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	public static function feed( $content ) {
		if ( INMO_CPT === get_post_type() ) {
			$v = Inmo_Vista_Publica::de( get_post() );
			return $v ? esc_html( $v['codigo'] . ' · ' . $v['precio_texto'] . ' · ' . $v['comuna'] ) : '';
		}
		return $content;
	}

	/** En feeds de propiedades el autor es la corredora, no el corredor (dato interno). */
	public static function autor_feed( $autor ) {
		return ( is_feed() && INMO_CPT === get_post_type() ) ? Inmo_Ajustes::get( 'marca_nombre' ) : $autor;
	}

	public static function rest_adjunto( $response, $post, $request ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			$data = $response->get_data();
			unset( $data['author'] );
			$response->set_data( $data );
			$response->remove_link( 'author' );
		}
		return $response;
	}

	public static function registrar_exportador( $e ) {
		$e['inmo-chile'] = array( 'exporter_friendly_name' => 'Inmo Chile — CRM', 'callback' => array( __CLASS__, 'exportar' ) );
		return $e;
	}

	public static function registrar_borrador( $e ) {
		$e['inmo-chile'] = array( 'eraser_friendly_name' => 'Inmo Chile — CRM', 'callback' => array( __CLASS__, 'borrar' ) );
		return $e;
	}

	public static function exportar( $email, $page = 1 ) {
		$id    = Inmo_Contactos::buscar_existente( $email, '' );
		$items = array();
		if ( $id ) {
			$c       = Inmo_Contactos::get( $id );
			$items[] = array(
				'group_id'    => 'inmo-contacto',
				'group_label' => 'Contacto (corredora)',
				'item_id'     => 'inmo-contacto-' . $id,
				'data'        => array(
					array( 'name' => 'Nombre', 'value' => $c->nombre ),
					array( 'name' => 'Correo', 'value' => $c->email ),
					array( 'name' => 'Teléfono', 'value' => $c->telefono ),
					array( 'name' => 'RUT', 'value' => Inmo_Util::formatear_rut( $c->rut ) ),
					array( 'name' => 'Consentimiento', 'value' => (string) $c->consentimiento ),
				),
			);
			foreach ( Inmo_Consultas::de_contacto( $id ) as $q ) {
				$items[] = array(
					'group_id'    => 'inmo-consultas',
					'group_label' => 'Consultas',
					'item_id'     => 'inmo-consulta-' . $q->id,
					'data'        => array(
						array( 'name' => 'Tipo', 'value' => Inmo_Consultas::TIPOS[ $q->tipo ] ),
						array( 'name' => 'Propiedad', 'value' => $q->codigo ),
						array( 'name' => 'Mensaje', 'value' => $q->mensaje ),
						array( 'name' => 'Fecha', 'value' => $q->creado ),
					),
				);
			}
		}
		return array( 'data' => $items, 'done' => true );
	}

	/** Anonimiza (conserva la trazabilidad comercial sin datos personales). */
	public static function borrar( $email, $page = 1 ) {
		global $wpdb;
		$id = Inmo_Contactos::buscar_existente( $email, '' );
		if ( ! $id ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$wpdb->update( Inmo_Contactos::tabla(), array( 'nombre' => 'Contacto anonimizado #' . $id, 'email' => '', 'telefono' => '', 'rut' => '', 'preferencias' => '', 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $id ) );
		$wpdb->update( Inmo_Consultas::tabla(), array( 'mensaje' => '[anonimizado]' ), array( 'contacto_id' => $id ) );
		Inmo_Historial::registrar( 'contacto', 'Datos personales anonimizados por solicitud del titular', array( 'contacto_id' => $id ) );
		return array( 'items_removed' => true, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
