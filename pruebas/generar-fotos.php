<?php
// Fotos de prueba (ilustraciones generadas, sin derechos de terceros) que "sube la propietaria".
foreach ( array( 'living' => array( 230, 220, 200 ), 'cocina' => array( 210, 225, 230 ) ) as $n => $c ) {
	$im = imagecreatetruecolor( 1024, 768 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, $c[0], $c[1], $c[2] ) );
	imagefilledrectangle( $im, 0, 560, 1024, 768, imagecolorallocate( $im, 150, 110, 80 ) );
	imagefilledrectangle( $im, 380, 140, 640, 420, imagecolorallocate( $im, 190, 220, 240 ) );
	imagestring( $im, 5, 30, 30, "Foto de prueba: $n (propietaria ficticia)", imagecolorallocate( $im, 40, 40, 40 ) );
	imagejpeg( $im, __DIR__ . "/fotos/$n.jpg", 80 );
}
echo "ok\n";
