<?php
$_SERVER['HTTP_HOST']='localhost:8080'; $_SERVER['REQUEST_URI']='/';
require __DIR__.'/core/wp-load.php';
require_once ABSPATH.'wp-admin/includes/user.php';
global $wpdb;
foreach (get_posts(['post_type'=>['inmo_propiedad','attachment'],'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']) as $id) wp_delete_post($id,true);
foreach (['inmo_oportunidades','inmo_ofertas','inmo_emisiones','inmo_contactos','inmo_propiedad_contacto','inmo_propiedad_privado','inmo_consultas','inmo_documentos','inmo_invitaciones','inmo_historial'] as $t) $wpdb->query("TRUNCATE {$wpdb->prefix}$t");
foreach (['camila.rojas','diego.munoz'] as $l) { $u=get_user_by('login',$l); if($u) wp_delete_user($u->ID); }
delete_option('inmo_datos_prueba'); update_option('inmo_codigo_seq',0);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%inmo_%'");
echo "reset\n";
