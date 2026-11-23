<?php
$_SERVER['HTTP_HOST']='localhost:8080'; $_SERVER['REQUEST_URI']='/';
require __DIR__.'/core/wp-load.php';
wp_set_current_user(1);
$r = Inmo_Datos_Prueba::cargar();
print_r($r);
foreach ($r['propiedades'] as $p) {
  echo $p,' ',get_post_status($p),' ',get_post_meta($p,'_inmo_codigo',true),' ',get_post_meta($p,'_inmo_estado',true),' ',get_permalink($p),' clp=',get_post_meta($p,'_inmo_precio_clp',true),"\n";
}
