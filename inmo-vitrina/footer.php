<?php
/**
 * Pie.
 *
 * @package InmoVitrina
 */
?>
</main>
<footer class="pie">
	<div class="contenedor pie__grid">
		<div>
			<p class="pie__marca"><?php echo esc_html( inmo_vitrina_marca( 'marca_nombre' ) ); ?></p>
			<p><?php echo esc_html( inmo_vitrina_marca( 'marca_eslogan' ) ); ?></p>
		</div>
		<div>
			<h2 class="pie__titulo">Contacto</h2>
			<ul class="pie__lista">
				<?php $tel = inmo_vitrina_marca( 'telefono' ); if ( $tel ) : ?>
					<li><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $tel ) ); ?>"><?php echo esc_html( $tel ); ?></a></li>
				<?php endif; ?>
				<?php $wa = inmo_vitrina_marca( 'whatsapp' ); if ( $wa ) : ?>
					<li><a href="https://wa.me/<?php echo esc_attr( preg_replace( '/\D/', '', $wa ) ); ?>" rel="noopener">WhatsApp <?php echo esc_html( $wa ); ?></a></li>
				<?php endif; ?>
				<?php $em = inmo_vitrina_marca( 'email' ); if ( $em ) : ?>
					<li><a href="mailto:<?php echo esc_attr( $em ); ?>"><?php echo esc_html( $em ); ?></a></li>
				<?php endif; ?>
				<li><?php echo esc_html( inmo_vitrina_marca( 'direccion_oficina' ) ); ?></li>
				<li><?php echo esc_html( inmo_vitrina_marca( 'horario' ) ); ?></li>
			</ul>
		</div>
		<div>
			<h2 class="pie__titulo">Propietarios</h2>
			<p><a href="<?php echo esc_url( inmo_vitrina_url_pagina( 'inmo_pagina_propietarios', '/publica-tu-propiedad/' ) ); ?>">Vende o arrienda con nosotros</a></p>
			<p><a href="<?php echo esc_url( add_query_arg( 'quiero', 'valorizar', inmo_vitrina_url_pagina( 'inmo_pagina_propietarios', '/publica-tu-propiedad/' ) ) ); ?>">Solicita una valorización</a></p>
			<?php if ( get_privacy_policy_url() ) : ?><p><a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">Política de privacidad</a></p><?php endif; ?>
		</div>
	</div>
	<p class="contenedor pie__legal">© <?php echo esc_html( wp_date( 'Y' ) . ' ' . inmo_vitrina_marca( 'marca_nombre' ) ); ?>. Imágenes ilustrativas en propiedades de prueba.</p>
</footer>
<?php wp_footer(); ?>
</body>
</html>
