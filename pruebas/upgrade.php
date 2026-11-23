<?php
$_SERVER['HTTP_HOST']='localhost:8080'; $_SERVER['REQUEST_URI']='/';
define('WP_INSTALLING', true);
require __DIR__.'/core/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
wp_upgrade();
update_option('WPLANG','es_CL');
echo "db ", get_option('db_version'), "\n";
