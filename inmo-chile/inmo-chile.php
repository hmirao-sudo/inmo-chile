<?php
/**
 * Plugin Name:       Inmo Chile — Gestión inmobiliaria
 * Description:       Propiedades, captación, CRM de consultas y vitrina pública para corredoras de propiedades en Chile. Entregas 1 y 2.
 * Version:           0.2.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Equipo de desarrollo
 * License:           GPL-2.0-or-later
 * Text Domain:       inmo-chile
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

define( 'INMO_VERSION', '0.2.0' );
define( 'INMO_DB_VERSION', '2' );
define( 'INMO_FILE', __FILE__ );
define( 'INMO_DIR', plugin_dir_path( __FILE__ ) );
define( 'INMO_URL', plugin_dir_url( __FILE__ ) );
define( 'INMO_CPT', 'inmo_propiedad' );

$inmo_archivos = array(
	'class-inmo-util',
	'class-inmo-esquema',
	'class-inmo-ajustes',
	'class-inmo-permisos',
	'class-inmo-historial',
	'class-inmo-privado',
	'class-inmo-contactos',
	'class-inmo-consultas',
	'class-inmo-documentos',
	'class-inmo-invitaciones',
	'class-inmo-propiedades',
	'class-inmo-vista-publica',
	'class-inmo-busqueda',
	'class-inmo-correo',
	'class-inmo-formularios',
	'class-inmo-privacidad',
	'class-inmo-oportunidades',
	'class-inmo-ofertas',
	'class-inmo-plantillas',
	'class-inmo-contratos',
	'class-inmo-expediente',
	'class-inmo-instalador',
	'class-inmo-datos-prueba',
);
foreach ( $inmo_archivos as $inmo_archivo ) {
	require_once INMO_DIR . 'includes/' . $inmo_archivo . '.php';
}
unset( $inmo_archivos, $inmo_archivo );

if ( is_admin() ) {
	require_once INMO_DIR . 'includes/admin/class-inmo-admin.php';
	require_once INMO_DIR . 'includes/admin/class-inmo-admin-propiedad.php';
	require_once INMO_DIR . 'includes/admin/class-inmo-admin-contactos.php';
	require_once INMO_DIR . 'includes/admin/class-inmo-admin-consultas.php';
	require_once INMO_DIR . 'includes/admin/class-inmo-admin-ajustes.php';
	require_once INMO_DIR . 'includes/admin/class-inmo-admin-oportunidades.php';
}

register_activation_hook( __FILE__, array( 'Inmo_Instalador', 'activar' ) );
register_deactivation_hook( __FILE__, array( 'Inmo_Instalador', 'desactivar' ) );

add_action(
	'plugins_loaded',
	static function () {
		Inmo_Instalador::verificar_version();
		Inmo_Propiedades::init();
		Inmo_Busqueda::init();
		Inmo_Formularios::init();
		Inmo_Documentos::init();
		Inmo_Privacidad::init();
		Inmo_Oportunidades::init();
		Inmo_Plantillas::init();
		Inmo_Contratos::init();
		if ( is_admin() ) {
			Inmo_Admin::init();
			Inmo_Admin_Propiedad::init();
			Inmo_Admin_Contactos::init();
			Inmo_Admin_Consultas::init();
			Inmo_Admin_Ajustes::init();
			Inmo_Admin_Oportunidades::init();
		}
	}
);

/**
 * API para el tema: lee un ajuste de marca/configuración.
 *
 * @param string $clave   Clave del ajuste.
 * @param mixed  $defecto Valor por defecto.
 * @return mixed
 */
function inmo_ajuste( $clave, $defecto = '' ) {
	return Inmo_Ajustes::get( $clave, $defecto );
}

/**
 * API para el tema: modelo de vista pública (sin datos privados) de una propiedad.
 *
 * @param int|WP_Post|null $post Propiedad.
 * @return array|null
 */
function inmo_propiedad( $post = null ) {
	return Inmo_Vista_Publica::de( $post );
}
