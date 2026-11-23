<?php
/**
 * Activación: tablas propias, roles, términos (operación, tipo, regiones/comunas) y páginas públicas.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Instalador {

	public static function activar() {
		self::crear_tablas();
		Inmo_Permisos::registrar_roles();
		Inmo_Propiedades::registrar();
		self::crear_terminos();
		self::crear_paginas();
		update_option( 'wp_attachment_pages_enabled', 0 ); // Sin páginas de adjunto públicas.
		Inmo_Documentos::directorio();
		update_option( 'inmo_db_version', INMO_DB_VERSION );
		flush_rewrite_rules();
	}

	public static function desactivar() {
		flush_rewrite_rules();
	}

	public static function verificar_version() {
		if ( get_option( 'inmo_db_version' ) !== INMO_DB_VERSION ) {
			self::crear_tablas();
			Inmo_Permisos::registrar_roles();
			update_option( 'inmo_db_version', INMO_DB_VERSION );
		}
	}

	public static function crear_tablas() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		$p  = $wpdb->prefix;

		dbDelta(
			"CREATE TABLE {$p}inmo_contactos (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nombre varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			telefono varchar(20) NOT NULL DEFAULT '',
			rut varchar(12) NOT NULL DEFAULT '',
			roles varchar(100) NOT NULL DEFAULT '',
			origen varchar(40) NOT NULL DEFAULT '',
			corredor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			preferencias longtext NULL,
			consentimiento datetime NULL,
			creado datetime NOT NULL,
			actualizado datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY telefono (telefono),
			KEY corredor_id (corredor_id)
		) $cs;"
		);

		dbDelta(
			"CREATE TABLE {$p}inmo_propiedad_contacto (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			propiedad_id bigint(20) unsigned NOT NULL,
			contacto_id bigint(20) unsigned NOT NULL,
			rol varchar(30) NOT NULL DEFAULT 'titular',
			creado datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY relacion (propiedad_id,contacto_id,rol),
			KEY contacto_id (contacto_id)
		) $cs;"
		);

		dbDelta(
			"CREATE TABLE {$p}inmo_propiedad_privado (
			propiedad_id bigint(20) unsigned NOT NULL,
			direccion varchar(255) NOT NULL DEFAULT '',
			unidad varchar(60) NOT NULL DEFAULT '',
			rol_sii varchar(40) NOT NULL DEFAULT '',
			lat decimal(10,7) NULL,
			lng decimal(10,7) NULL,
			mandato_tipo varchar(30) NOT NULL DEFAULT '',
			exclusividad varchar(5) NOT NULL DEFAULT 'no',
			comision_pct decimal(5,2) NULL,
			comision_detalle varchar(255) NOT NULL DEFAULT '',
			captacion_vence date NULL,
			precio_minimo decimal(14,2) NULL,
			notas text NULL,
			actualizado datetime NOT NULL,
			PRIMARY KEY  (propiedad_id)
		) $cs;"
		);

		dbDelta(
			"CREATE TABLE {$p}inmo_consultas (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tipo varchar(20) NOT NULL,
			contacto_id bigint(20) unsigned NOT NULL,
			propiedad_id bigint(20) unsigned NOT NULL DEFAULT 0,
			codigo varchar(20) NOT NULL DEFAULT '',
			mensaje text NULL,
			datos longtext NULL,
			estado varchar(20) NOT NULL,
			visita_fecha datetime NULL,
			corredor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origen varchar(40) NOT NULL DEFAULT 'web',
			consentimiento datetime NULL,
			creado datetime NOT NULL,
			actualizado datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY contacto_id (contacto_id),
			KEY propiedad_id (propiedad_id),
			KEY tipo_estado (tipo,estado),
			KEY corredor_id (corredor_id)
		) $cs;"
		);

		dbDelta(
			"CREATE TABLE {$p}inmo_documentos (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			propiedad_id bigint(20) unsigned NOT NULL DEFAULT 0,
			contacto_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tipo varchar(30) NOT NULL,
			nombre varchar(255) NOT NULL DEFAULT '',
			ruta varchar(255) NOT NULL,
			mime varchar(100) NOT NULL DEFAULT '',
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			sha256 char(64) NOT NULL DEFAULT '',
			estado varchar(20) NOT NULL DEFAULT 'vigente',
			subido_por bigint(20) unsigned NOT NULL DEFAULT 0,
			creado datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY propiedad_id (propiedad_id)
		) $cs;"
		);

		dbDelta(
			"CREATE TABLE {$p}inmo_invitaciones (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			propiedad_id bigint(20) unsigned NOT NULL,
			contacto_id bigint(20) unsigned NOT NULL DEFAULT 0,
			token_hash char(64) NOT NULL,
			vence datetime NOT NULL,
			usada datetime NULL,
			anulada tinyint(1) NOT NULL DEFAULT 0,
			creado datetime NOT NULL,
			creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY propiedad_id (propiedad_id)
		) $cs;"
		);

		dbDelta(
			"CREATE TABLE {$p}inmo_historial (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			propiedad_id bigint(20) unsigned NOT NULL DEFAULT 0,
			contacto_id bigint(20) unsigned NOT NULL DEFAULT 0,
			consulta_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			accion varchar(40) NOT NULL,
			detalle text NULL,
			creado datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY propiedad_id (propiedad_id),
			KEY contacto_id (contacto_id)
		) $cs;"
		);
	}

	public static function crear_terminos() {
		foreach ( Inmo_Esquema::OPERACIONES as $slug => $nombre ) {
			if ( ! term_exists( $slug, Inmo_Propiedades::TAX_OPERACION ) ) {
				wp_insert_term( $nombre, Inmo_Propiedades::TAX_OPERACION, array( 'slug' => $slug ) );
			}
		}
		foreach ( Inmo_Esquema::TIPOS as $slug => $nombre ) {
			if ( ! term_exists( $slug, Inmo_Propiedades::TAX_TIPO ) ) {
				wp_insert_term( $nombre, Inmo_Propiedades::TAX_TIPO, array( 'slug' => $slug ) );
			}
		}
		foreach ( Inmo_Esquema::regiones_comunas() as $region => $comunas ) {
			$r = term_exists( sanitize_title( $region ), Inmo_Propiedades::TAX_UBICACION );
			if ( ! $r ) {
				$r = wp_insert_term( $region, Inmo_Propiedades::TAX_UBICACION, array( 'slug' => sanitize_title( $region ) ) );
			}
			if ( is_wp_error( $r ) ) {
				continue;
			}
			$rid = (int) $r['term_id'];
			foreach ( $comunas as $comuna ) {
				if ( ! term_exists( sanitize_title( $comuna ), Inmo_Propiedades::TAX_UBICACION ) ) {
					wp_insert_term( $comuna, Inmo_Propiedades::TAX_UBICACION, array( 'slug' => sanitize_title( $comuna ), 'parent' => $rid ) );
				}
			}
		}
	}

	public static function crear_paginas() {
		$paginas = array(
			'inmo_pagina_propietarios' => array( 'publica-tu-propiedad', 'Vende o arrienda tu propiedad', '[inmo_formulario_propietario]' ),
			'inmo_pagina_completar'    => array( 'completar-propiedad', 'Completa la ficha de tu propiedad', '[inmo_completar_propiedad]' ),
			'inmo_pagina_contacto'     => array( 'contacto', 'Contacto', '[inmo_formulario_general]' ),
		);
		foreach ( $paginas as $opcion => $p ) {
			$id = (int) get_option( $opcion );
			if ( $id && get_post( $id ) ) {
				continue;
			}
			$existente = get_page_by_path( $p[0] );
			$id        = $existente ? $existente->ID : wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => $p[0],
					'post_title'   => $p[1],
					'post_content' => '<!-- wp:shortcode -->' . $p[2] . '<!-- /wp:shortcode -->',
				)
			);
			update_option( $opcion, (int) $id );
		}
		// La página de invitación no debe indexarse.
		update_post_meta( (int) get_option( 'inmo_pagina_completar' ), '_inmo_noindex', 1 );
	}
}
