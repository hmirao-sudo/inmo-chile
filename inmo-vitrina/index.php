<?php
/**
 * Plantilla general.
 *
 * @package InmoVitrina
 */

get_header();
?>
<div class="contenedor contenedor--estrecho pagina">
	<?php if ( have_posts() ) : ?>
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'bloque' ); ?>>
				<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<?php the_excerpt(); ?>
			</article>
		<?php endwhile; ?>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<h1>No hay contenido</h1>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Volver al inicio</a></p>
	<?php endif; ?>
</div>
<?php
get_footer();
