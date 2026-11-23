<?php
// Router para el servidor embebido de PHP (equivale a las reglas de reescritura de WordPress).
// Equivale al .htaccess de Apache que bloquea el almacenamiento privado.
if ( false !== strpos( $_SERVER['REQUEST_URI'], '/uploads/inmo-privado/' ) ) {
	http_response_code( 403 );
	echo 'Acceso prohibido';
	return true;
}
$ruta = __DIR__ . '/core' . parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( is_file( $ruta ) && ! preg_match( '/\.php$/', $ruta ) ) {
	return false;
}
if ( is_file( $ruta ) ) {
	$_SERVER['SCRIPT_FILENAME'] = $ruta;
	$_SERVER['SCRIPT_NAME'] = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
	chdir( dirname( $ruta ) );
	require $ruta;
	return true;
}
if ( is_dir( $ruta ) && is_file( rtrim( $ruta, '/' ) . '/index.php' ) ) {
	chdir( rtrim( $ruta, '/' ) );
	require rtrim( $ruta, '/' ) . '/index.php';
	return true;
}
chdir( __DIR__ . '/core' );
require __DIR__ . '/core/index.php';
