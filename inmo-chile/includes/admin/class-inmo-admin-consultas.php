<?php
/**
 * Bandeja de consultas, visitas, captaciones y valorizaciones.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Admin_Consultas {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_inmo_consultas', array( __CLASS__, 'procesar' ) );
	}

	public static function menu() {
		add_submenu_page( 'inmo', 'Consultas y visitas', 'Consultas y visitas', 'inmo_gestionar', 'inmo-consultas', array( __CLASS__, 'pagina' ) );
	}

	public static function procesar() {
		check_admin_referer( 'inmo_consultas' );
		if ( ! current_user_can( 'inmo_gestionar' ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		Inmo_Admin_Propiedad::acciones_consultas( 'inmo_q' );
		$volver = isset( $_POST['_volver'] ) ? esc_url_raw( wp_unslash( $_POST['_volver'] ) ) : admin_url( 'admin.php?page=inmo-consultas' );
		wp_safe_redirect( $volver );
		exit;
	}

	/** Controles para cambiar estado (sin <form> propio: se insertan dentro de un formulario mayor). */
	public static function controles_inline( $q, $prefijo ) {
		$id = (int) $q->id;
		$n  = $prefijo . '[' . $id . ']';
		echo '<div class="inmo-ctrl">';
		echo '<label class="screen-reader-text" for="' . esc_attr( $prefijo . '-e-' . $id ) . '">Nuevo estado</label>';
		echo '<select name="' . esc_attr( $n ) . '[estado]" id="' . esc_attr( $prefijo . '-e-' . $id ) . '" data-inmo-estado>';
		foreach ( Inmo_Consultas::estados_de( $q->tipo ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $q->estado, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		if ( 'visita' === $q->tipo ) {
			$val = $q->visita_fecha ? str_replace( ' ', 'T', substr( $q->visita_fecha, 0, 16 ) ) : '';
			if ( ! $val && ! empty( $q->datos['fecha_preferida'] ) ) {
				$val = $q->datos['fecha_preferida'] . ( 'tarde' === ( $q->datos['franja'] ?? '' ) ? 'T16:00' : 'T10:00' );
			}
			echo '<label class="screen-reader-text" for="' . esc_attr( $prefijo . '-f-' . $id ) . '">Fecha y hora de la visita</label>';
			echo '<input type="datetime-local" name="' . esc_attr( $n ) . '[fecha]" id="' . esc_attr( $prefijo . '-f-' . $id ) . '" value="' . esc_attr( $val ) . '">';
		}
		echo '<label class="screen-reader-text" for="' . esc_attr( $prefijo . '-n-' . $id ) . '">Nota para el contacto</label>';
		echo '<input type="text" name="' . esc_attr( $n ) . '[nota]" id="' . esc_attr( $prefijo . '-n-' . $id ) . '" placeholder="Nota (opcional)">';
		echo '<button type="submit" class="button" name="' . esc_attr( $prefijo ) . '_aplicar" value="' . $id . '">Aplicar</button>';
		echo '</div>';
	}

	/** Tabla compacta para el panel. */
	public static function tabla_compacta( array $filas, $visitas = false ) {
		if ( ! $filas ) {
			echo '<p>Nada pendiente.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Fecha</th><th>Contacto</th><th>' . ( $visitas ? 'Preferencia' : 'Tipo' ) . '</th><th>Propiedad</th></tr></thead><tbody>';
		foreach ( $filas as $q ) {
			$datos = is_array( $q->datos ) ? $q->datos : ( $q->datos ? json_decode( $q->datos, true ) : array() );
			echo '<tr><td>' . esc_html( Inmo_Util::fecha( $q->creado, true ) ) . '</td>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $q->contacto_id ) ) . '">' . esc_html( $q->nombre ) . '</a></td>';
			if ( $visitas ) {
				echo '<td>' . esc_html( Inmo_Util::fecha( $datos['fecha_preferida'] ?? '' ) . ' · ' . ( Inmo_Consultas::FRANJAS[ $datos['franja'] ?? '' ] ?? '' ) ) . '</td>';
			} else {
				echo '<td>' . esc_html( Inmo_Consultas::TIPOS[ $q->tipo ] ) . '</td>';
			}
			echo '<td>' . ( $q->propiedad_id ? '<a href="' . esc_url( get_edit_post_link( $q->propiedad_id ) ) . '">' . esc_html( $q->codigo ) . '</a>' : '<em>General</em>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function pagina() {
		$tipo   = Inmo_Util::get( 'tipo' );
		$estado = Inmo_Util::get( 'estado' );
		$pag    = max( 1, absint( Inmo_Util::get( 'paged', 1 ) ) );
		list( $filas, $total ) = Inmo_Consultas::listar( array( 'tipo' => $tipo, 'estado' => $estado, 'pagina' => $pag ) );
		$base = admin_url( 'admin.php?page=inmo-consultas' );
		?>
		<div class="wrap inmo-wrap">
			<h1>Consultas y visitas</h1>
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base ); ?>" <?php echo ! $tipo ? 'class="current"' : ''; ?>>Todas</a> |</li>
				<?php foreach ( Inmo_Consultas::TIPOS as $k => $l ) : ?>
					<li><a href="<?php echo esc_url( add_query_arg( 'tipo', $k, $base ) ); ?>" <?php echo $tipo === $k ? 'class="current"' : ''; ?>><?php echo esc_html( $l ); ?></a> |</li>
				<?php endforeach; ?>
				<li><a href="<?php echo esc_url( add_query_arg( array( 'tipo' => 'visita', 'estado' => 'pendiente' ), $base ) ); ?>">Visitas por confirmar</a></li>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="inmo_consultas">
				<input type="hidden" name="_volver" value="<?php echo esc_url( add_query_arg( array( 'tipo' => $tipo, 'estado' => $estado, 'paged' => $pag ), $base ) ); ?>">
				<?php wp_nonce_field( 'inmo_consultas' ); ?>
				<table class="widefat striped inmo-actividad">
					<thead><tr><th>Fecha</th><th>Tipo</th><th>Contacto</th><th>Propiedad</th><th>Mensaje</th><th>Estado</th><th>Acción</th></tr></thead>
					<tbody>
					<?php if ( ! $filas ) : ?>
						<tr><td colspan="7">No hay registros.</td></tr>
					<?php endif; ?>
					<?php
					foreach ( $filas as $q ) :
						$q->datos = $q->datos ? json_decode( $q->datos, true ) : array();
						?>
						<tr>
							<td><?php echo esc_html( Inmo_Util::fecha( $q->creado, true ) ); ?></td>
							<td><?php echo esc_html( Inmo_Consultas::TIPOS[ $q->tipo ] ); ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $q->contacto_id ) ); ?>"><?php echo esc_html( $q->nombre ); ?></a><br><span class="description"><?php echo esc_html( $q->email . ' ' . $q->telefono ); ?></span></td>
							<td><?php echo $q->propiedad_id ? '<a href="' . esc_url( get_edit_post_link( $q->propiedad_id ) ) . '">' . esc_html( $q->codigo ) . '</a>' : '<em>General</em>'; ?></td>
							<td><?php echo esc_html( wp_trim_words( $q->mensaje, 20 ) ); ?>
								<?php if ( 'visita' === $q->tipo ) : ?>
									<br><span class="description">Prefiere: <?php echo esc_html( Inmo_Util::fecha( $q->datos['fecha_preferida'] ?? '' ) . ' · ' . ( Inmo_Consultas::FRANJAS[ $q->datos['franja'] ?? '' ] ?? '' ) ); ?></span>
									<?php if ( $q->visita_fecha ) : ?><br><strong>Cita: <?php echo esc_html( Inmo_Util::fecha( $q->visita_fecha, true ) ); ?></strong><?php endif; ?>
								<?php endif; ?>
								<?php if ( ! empty( $q->datos['busqueda'] ) ) : ?>
									<br><span class="description">Búsqueda: <?php echo esc_html( http_build_query( $q->datos['busqueda'], '', ', ' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="inmo-badge inmo-badge--<?php echo esc_attr( $q->estado ); ?>"><?php echo esc_html( Inmo_Consultas::etiqueta_estado( $q ) ); ?></span></td>
							<td><?php self::controles_inline( $q, 'inmo_q' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>
			<?php
			$paginas = (int) ceil( $total / 25 );
			if ( $paginas > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'current' => $pag, 'total' => $paginas ) ) ) . '</div></div>';
			}
			?>
		</div>
		<?php
	}
}
