<?php
/**
 * Desinstalación. Por seguridad NO borra el catálogo ni el CRM salvo que se defina
 * define( 'INMO_BORRAR_DATOS_AL_DESINSTALAR', true ); en wp-config.php.
 *
 * @package InmoChile
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'INMO_BORRAR_DATOS_AL_DESINSTALAR' ) || ! INMO_BORRAR_DATOS_AL_DESINSTALAR ) {
	return;
}

global $wpdb;
foreach ( array( 'inmo_contactos', 'inmo_propiedad_contacto', 'inmo_propiedad_privado', 'inmo_consultas', 'inmo_documentos', 'inmo_invitaciones', 'inmo_historial' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); // phpcs:ignore
}
foreach ( array( 'inmo_ajustes', 'inmo_db_version', 'inmo_codigo_seq', 'inmo_datos_prueba', 'inmo_pagina_propietarios', 'inmo_pagina_completar', 'inmo_pagina_contacto' ) as $o ) {
	delete_option( $o );
}
remove_role( 'inmo_corredor' );
