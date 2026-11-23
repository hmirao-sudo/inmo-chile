<?php
/**
 * Menú "Inmobiliaria" y panel de resumen.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'avisos' ) );
		add_filter( 'parent_file', array( __CLASS__, 'menu_activo' ) );
	}

	public static function menu() {
		$n = Inmo_Consultas::contar( 'visita', array( 'pendiente' ) ) + Inmo_Consultas::contar( 'consulta', array( 'nueva' ) ) + Inmo_Consultas::contar( 'captacion', array( 'nueva' ) );
		$b = $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
		add_menu_page( 'Inmobiliaria', 'Inmobiliaria' . $b, 'inmo_gestionar', 'inmo', array( __CLASS__, 'panel' ), 'dashicons-building', 25 );
		add_submenu_page( 'inmo', 'Panel', 'Panel', 'inmo_gestionar', 'inmo', array( __CLASS__, 'panel' ) );
		// El listado de propiedades lo agrega el CPT (show_in_menu = inmo).
		add_submenu_page( 'inmo', 'Nueva captación', 'Nueva captación', 'edit_inmo_propiedades', 'post-new.php?post_type=' . INMO_CPT );
		add_submenu_page( 'inmo', 'Regiones y comunas', 'Regiones y comunas', 'inmo_ajustes', 'edit-tags.php?taxonomy=' . Inmo_Propiedades::TAX_UBICACION . '&post_type=' . INMO_CPT );
	}

	public static function menu_activo( $parent ) {
		global $current_screen;
		if ( $current_screen && Inmo_Propiedades::TAX_UBICACION === $current_screen->taxonomy ) {
			return 'inmo';
		}
		return $parent;
	}

	public static function es_pantalla_inmo() {
		$s = get_current_screen();
		return $s && ( INMO_CPT === $s->post_type || false !== strpos( (string) $s->id, 'inmo' ) );
	}

	public static function assets( $hook ) {
		if ( ! self::es_pantalla_inmo() ) {
			return;
		}
		wp_enqueue_style( 'inmo-admin', INMO_URL . 'assets/admin.css', array(), INMO_VERSION );
		$s = get_current_screen();
		if ( $s && INMO_CPT === $s->post_type && 'post' === $s->base ) {
			wp_enqueue_media();
			wp_enqueue_script( 'jquery-ui-sortable' );
		}
		wp_enqueue_script( 'inmo-admin', INMO_URL . 'assets/admin.js', array( 'jquery' ), INMO_VERSION, true );
	}

	public static function avisos() {
		$m = get_transient( 'inmo_aviso_' . get_current_user_id() );
		if ( ! $m ) {
			return;
		}
		delete_transient( 'inmo_aviso_' . get_current_user_id() );
		foreach ( (array) $m as $a ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $a[0] ), wp_kses( $a[1], array( 'a' => array( 'href' => array() ), 'strong' => array(), 'code' => array(), 'input' => array( 'type' => array(), 'readonly' => array(), 'value' => array(), 'class' => array(), 'aria-label' => array() ), 'button' => array( 'type' => array(), 'class' => array(), 'data-inmo-copiar' => array() ) ) ) );
		}
	}

	/** Encola un aviso para la próxima carga del admin. */
	public static function avisar( $tipo, $html ) {
		$k = 'inmo_aviso_' . get_current_user_id();
		$m = (array) get_transient( $k );
		$m[] = array( $tipo, $html );
		set_transient( $k, array_filter( $m ), 300 );
	}

	public static function panel() {
		if ( ! current_user_can( 'inmo_gestionar' ) ) {
			wp_die( 'Sin permiso.' );
		}
		$en_revision = get_posts( self::args_propias( array( 'post_status' => 'pending', 'posts_per_page' => 20 ) ) );
		list( $visitas ) = Inmo_Consultas::listar( array( 'tipo' => 'visita', 'estado' => 'pendiente', 'por_pagina' => 10 ) );
		list( $nuevas )  = Inmo_Consultas::listar( array( 'estado' => 'nueva', 'por_pagina' => 10 ) );
		$por_vencer      = self::captaciones_por_vencer();
		?>
		<div class="wrap inmo-wrap">
			<h1>Panel de la corredora</h1>
			<?php if ( current_user_can( 'inmo_ajustes' ) && ! Inmo_Datos_Prueba::cargados() ) : ?>
				<div class="notice notice-info"><p>¿Quieres probar con datos ficticios? <a href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-ajustes#datos-prueba' ) ); ?>">Cargar datos de prueba</a></p></div>
			<?php endif; ?>
			<div class="inmo-kpis">
				<a class="inmo-kpi" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . INMO_CPT . '&post_status=pending' ) ); ?>"><strong><?php echo count( $en_revision ); ?></strong> captaciones en revisión</a>
				<a class="inmo-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-consultas&tipo=visita&estado=pendiente' ) ); ?>"><strong><?php echo (int) Inmo_Consultas::contar( 'visita', array( 'pendiente' ) ); ?></strong> visitas por confirmar</a>
				<a class="inmo-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-consultas&estado=nueva' ) ); ?>"><strong><?php echo (int) ( Inmo_Consultas::contar( 'consulta', array( 'nueva' ) ) + Inmo_Consultas::contar( 'general', array( 'nueva' ) ) + Inmo_Consultas::contar( 'captacion', array( 'nueva' ) ) + Inmo_Consultas::contar( 'valorizacion', array( 'nueva' ) ) ); ?></strong> consultas nuevas</a>
				<span class="inmo-kpi"><strong><?php echo count( $por_vencer ); ?></strong> captaciones vencen en 30 días</span>
				<a class="inmo-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-cobranza' ) ); ?>"><strong><?php echo count( Inmo_Arriendos::morosos() ); ?></strong> cobros de arriendo vencidos</a>
				<a class="inmo-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-liquidaciones' ) ); ?>"><strong><?php echo count( Inmo_Liquidaciones::listar( '', 'emitida' ) ); ?></strong> liquidaciones por transferir</a>
				<a class="inmo-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-oportunidades' ) ); ?>"><strong><?php echo count( Inmo_Oportunidades::listar( array( 'incluir_cerradas' => false ) ) ); ?></strong> oportunidades abiertas</a>
			</div>

			<div class="inmo-columnas">
				<section class="inmo-caja">
					<h2>Captaciones en revisión</h2>
					<?php if ( ! $en_revision ) : ?>
						<p>No hay captaciones pendientes.</p>
					<?php else : ?>
						<table class="widefat striped"><thead><tr><th>Código</th><th>Propiedad</th><th>Recibida</th></tr></thead><tbody>
						<?php foreach ( $en_revision as $p ) : ?>
							<tr><td><?php echo esc_html( get_post_meta( $p->ID, '_inmo_codigo', true ) ); ?></td><td><a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></td><td><?php echo esc_html( Inmo_Util::fecha( $p->post_date ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
				</section>
				<section class="inmo-caja">
					<h2>Visitas por confirmar</h2>
					<?php Inmo_Admin_Consultas::tabla_compacta( $visitas, true ); ?>
				</section>
				<section class="inmo-caja">
					<h2>Consultas nuevas</h2>
					<?php Inmo_Admin_Consultas::tabla_compacta( $nuevas ); ?>
				</section>
				<section class="inmo-caja">
					<h2>Captaciones por vencer</h2>
					<?php if ( ! $por_vencer ) : ?>
						<p>Ninguna en los próximos 30 días.</p>
					<?php else : ?>
						<ul><?php foreach ( $por_vencer as $r ) : ?>
							<li><a href="<?php echo esc_url( get_edit_post_link( $r->propiedad_id ) ); ?>"><?php echo esc_html( get_post_meta( $r->propiedad_id, '_inmo_codigo', true ) . ' — ' . get_the_title( $r->propiedad_id ) ); ?></a> · vence <?php echo esc_html( Inmo_Util::fecha( $r->captacion_vence ) ); ?></li>
						<?php endforeach; ?></ul>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php
	}

	/** Restringe a propiedades propias si el usuario no ve todo. */
	public static function args_propias( array $args ) {
		$args['post_type'] = INMO_CPT;
		if ( ! Inmo_Permisos::ve_todo() ) {
			$args['author'] = get_current_user_id();
		}
		return $args;
	}

	private static function captaciones_por_vencer() {
		global $wpdb;
		$t   = Inmo_Privado::tabla();
		$and = Inmo_Permisos::ve_todo() ? '' : $wpdb->prepare( ' AND p.post_author = %d', get_current_user_id() );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT x.propiedad_id, x.captacion_vence FROM {$t} x JOIN {$wpdb->posts} p ON p.ID = x.propiedad_id WHERE x.captacion_vence IS NOT NULL AND x.captacion_vence BETWEEN %s AND %s AND p.post_status NOT IN ('trash','auto-draft') {$and} ORDER BY x.captacion_vence", // phpcs:ignore
				current_time( 'Y-m-d' ),
				gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +30 days' ) )
			)
		);
	}
}
