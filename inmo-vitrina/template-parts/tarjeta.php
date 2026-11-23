<?php
/**
 * Tarjeta de resultado. Usa solo el modelo de vista pública.
 *
 * @package InmoVitrina
 */

$v = inmo_propiedad( get_the_ID() );
if ( ! $v ) {
	return;
}
$alt = $v['tipo_label'] . ' en ' . strtolower( $v['operacion_label'] ) . ' en ' . $v['comuna'];
?>
<article class="tarjeta">
	<div class="tarjeta__media">
		<?php echo inmo_vitrina_imagen( $v['portada'], 'inmo-tarjeta', $alt ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<span class="etiqueta etiqueta--<?php echo esc_attr( $v['operacion'] ); ?>"><?php echo esc_html( $v['operacion_label'] ); ?></span>
		<?php if ( $v['reservada'] ) : ?><span class="etiqueta etiqueta--reservada">Reservada</span><?php endif; ?>
	</div>
	<div class="tarjeta__cuerpo">
		<p class="tarjeta__precio"><?php echo esc_html( $v['precio_texto'] ); ?><?php echo 'arriendo' === $v['operacion'] ? ' <span>/ mes</span>' : ''; ?></p>
		<h3 class="tarjeta__titulo"><a href="<?php echo esc_url( $v['url'] ); ?>"><?php echo esc_html( $v['titulo'] ); ?></a></h3>
		<p class="tarjeta__lugar"><?php echo esc_html( $v['tipo_label'] . ' · ' . $v['comuna'] . ( $v['sector'] ? ', ' . $v['sector'] : '' ) ); ?></p>
		<?php if ( $v['tarjeta'] ) : ?>
			<ul class="tarjeta__atributos">
				<?php foreach ( $v['tarjeta'] as $a ) : ?><li><?php echo esc_html( $a ); ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p class="tarjeta__codigo">Código <?php echo esc_html( $v['codigo'] ); ?></p>
	</div>
</article>
