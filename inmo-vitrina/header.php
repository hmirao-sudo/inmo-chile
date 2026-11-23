<?php
/**
 * Cabecera.
 *
 * @package InmoVitrina
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>document.documentElement.classList.add("js");</script>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="saltar" href="#contenido">Saltar al contenido</a>
<header class="cabecera">
	<div class="contenedor cabecera__fila">
		<a class="marca" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
			<?php
			$logo = (int) inmo_vitrina_marca( 'logo_id', 0 );
			if ( $logo ) {
				echo wp_get_attachment_image( $logo, 'medium', false, array( 'alt' => inmo_vitrina_marca( 'marca_nombre' ), 'class' => 'marca__logo' ) );
			} else {
				echo '<span class="marca__isotipo" aria-hidden="true">▲</span><span class="marca__nombre">' . esc_html( inmo_vitrina_marca( 'marca_nombre' ) ) . '</span>';
			}
			?>
		</a>
		<button class="menu-boton" type="button" aria-expanded="false" aria-controls="menu-principal">
			<span class="menu-boton__icono" aria-hidden="true"></span><span>Menú</span>
		</button>
		<nav id="menu-principal" class="navegacion" aria-label="Principal">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'principal',
					'container'      => false,
					'fallback_cb'    => 'inmo_vitrina_menu_defecto',
				)
			);
			?>
		</nav>
	</div>
</header>
<main id="contenido" tabindex="-1">
<?php if ( ! inmo_vitrina_plugin() ) : ?>
	<div class="contenedor"><p class="aviso">Activa el plugin «Inmo Chile» para ver el catálogo de propiedades.</p></div>
<?php endif; ?>
