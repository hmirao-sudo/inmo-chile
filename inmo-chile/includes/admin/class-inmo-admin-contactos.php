<?php
/**
 * CRM: listado y ficha del contacto con historial por inmueble.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Admin_Contactos {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_post_inmo_contacto', array( __CLASS__, 'guardar' ) );
	}

	public static function menu() {
		$hook = add_submenu_page( 'inmo', 'Contactos', 'Contactos (CRM)', 'inmo_gestionar', 'inmo-contactos', array( __CLASS__, 'pagina' ) );
		add_action( 'load-' . $hook, array( __CLASS__, 'verificar_acceso' ) );
	}

	/** Se valida antes de imprimir el admin para responder con HTTP 403 real. */
	public static function verificar_acceso() {
		$id = absint( Inmo_Util::get( 'contacto' ) );
		if ( $id && ( ! Inmo_Contactos::get( $id ) || ! Inmo_Permisos::puede_ver_contacto( $id ) ) ) {
			wp_die( 'No tienes acceso a este contacto.', 'Acceso denegado', array( 'response' => 403, 'back_link' => true ) );
		}
	}

	public static function pagina() {
		if ( ! current_user_can( 'inmo_gestionar' ) ) {
			wp_die( 'Sin permiso.' );
		}
		$id = absint( Inmo_Util::get( 'contacto' ) );
		if ( $id || 'nuevo' === Inmo_Util::get( 'accion' ) ) {
			self::ficha( $id );
			return;
		}
		$buscar = Inmo_Util::get( 's' );
		$rol    = Inmo_Util::get( 'rol' );
		$pag    = max( 1, absint( Inmo_Util::get( 'paged', 1 ) ) );
		list( $filas, $total ) = Inmo_Contactos::listar( array( 'buscar' => $buscar, 'rol' => $rol, 'pagina' => $pag ) );
		?>
		<div class="wrap inmo-wrap">
			<h1 class="wp-heading-inline">Contactos</h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-contactos&accion=nuevo' ) ); ?>">Nuevo contacto</a>
			<form method="get" class="inmo-filtros">
				<input type="hidden" name="page" value="inmo-contactos">
				<label class="screen-reader-text" for="inmo-buscar-c">Buscar contactos</label>
				<input type="search" id="inmo-buscar-c" name="s" value="<?php echo esc_attr( $buscar ); ?>" placeholder="Nombre, correo, teléfono o RUT">
				<label class="screen-reader-text" for="inmo-rol">Rol</label>
				<select name="rol" id="inmo-rol"><option value="">Todos los roles</option>
				<?php foreach ( Inmo_Contactos::ROLES as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $rol, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?></select>
				<button class="button">Filtrar</button>
			</form>
			<table class="widefat striped">
				<thead><tr><th>Nombre</th><th>Roles</th><th>Correo</th><th>Teléfono</th><th>Origen</th><th>Corredor</th><th>Consultas</th><th>Actualizado</th></tr></thead>
				<tbody>
				<?php if ( ! $filas ) : ?><tr><td colspan="8">Sin contactos.</td></tr><?php endif; ?>
				<?php foreach ( $filas as $c ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $c->id ) ); ?>"><strong><?php echo esc_html( $c->nombre ); ?></strong></a></td>
						<td><?php echo esc_html( Inmo_Contactos::etiqueta_roles( $c->roles ) ); ?></td>
						<td><?php echo esc_html( $c->email ); ?></td>
						<td><?php echo esc_html( $c->telefono ); ?></td>
						<td><?php echo esc_html( Inmo_Contactos::ORIGENES[ $c->origen ] ?? $c->origen ); ?></td>
						<td><?php echo esc_html( $c->corredor_id ? get_the_author_meta( 'display_name', $c->corredor_id ) : '—' ); ?></td>
						<td><?php echo (int) $c->n_consultas; ?></td>
						<td><?php echo esc_html( Inmo_Util::fecha( $c->actualizado ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$paginas = (int) ceil( $total / 25 );
			if ( $paginas > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'current' => $pag, 'total' => $paginas ) ) ) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	private static function ficha( $id ) {
		$c = $id ? Inmo_Contactos::get( $id ) : null;
		if ( $id && ( ! $c || ! Inmo_Permisos::puede_ver_contacto( $id ) ) ) {
			wp_die( 'No tienes acceso a este contacto.', '', array( 'response' => 403 ) );
		}
		$pref     = $c ? (array) $c->preferencias : array();
		$roles    = $c ? $c->roles_lista : array();
		$ver_todo = Inmo_Permisos::ve_todo();
		?>
		<div class="wrap inmo-wrap">
			<h1><?php echo $c ? esc_html( $c->nombre ) : 'Nuevo contacto'; ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-contactos' ) ); ?>">← Volver a contactos</a></p>
			<div class="inmo-columnas inmo-columnas--ficha">
			<section class="inmo-caja">
				<h2>Datos y preferencias</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="inmo_contacto">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<input type="hidden" name="op" value="datos">
					<?php wp_nonce_field( 'inmo_contacto_' . $id ); ?>
					<div class="inmo-grid">
						<p><label for="c_nombre">Nombre *</label><input type="text" id="c_nombre" name="nombre" required value="<?php echo esc_attr( $c->nombre ?? '' ); ?>"></p>
						<p><label for="c_rut">RUT</label><input type="text" id="c_rut" name="rut" value="<?php echo esc_attr( $c ? Inmo_Util::formatear_rut( $c->rut ) : '' ); ?>"></p>
						<p><label for="c_email">Correo</label><input type="email" id="c_email" name="email" value="<?php echo esc_attr( $c->email ?? '' ); ?>"></p>
						<p><label for="c_tel">Teléfono</label><input type="tel" id="c_tel" name="telefono" value="<?php echo esc_attr( $c->telefono ?? '' ); ?>"></p>
					</div>
					<fieldset><legend>Roles</legend>
						<?php foreach ( Inmo_Contactos::ROLES as $k => $l ) : ?>
							<label class="inmo-inline"><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $roles, true ) ); ?>> <?php echo esc_html( $l ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<p><label for="c_corredor">Corredor asignado</label>
					<select id="c_corredor" name="corredor_id" <?php disabled( ! $ver_todo && $c ); ?>>
						<?php foreach ( Inmo_Permisos::corredores() as $u ) : ?>
							<option value="<?php echo (int) $u->ID; ?>" <?php selected( $c ? (int) $c->corredor_id : get_current_user_id(), (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
						<?php endforeach; ?>
					</select></p>
					<h3>Busca</h3>
					<div class="inmo-grid">
						<p><label for="p_op">Operación</label><select id="p_op" name="pref[operacion]"><option value="">—</option>
							<?php foreach ( Inmo_Esquema::OPERACIONES as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $pref['operacion'] ?? '', $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></p>
						<p><label for="p_tipos">Tipos</label><select id="p_tipos" name="pref[tipos][]" multiple size="3">
							<?php foreach ( Inmo_Esquema::TIPOS as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( in_array( $k, (array) ( $pref['tipos'] ?? array() ), true ) ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></p>
						<p><label for="p_comunas">Comunas (separadas por coma)</label><input type="text" id="p_comunas" name="pref[comunas_texto]" value="<?php echo esc_attr( implode( ', ', array_map( array( __CLASS__, 'nombre_comuna' ), (array) ( $pref['comunas'] ?? array() ) ) ) ); ?>"></p>
						<p><label for="p_min">Presupuesto mínimo</label><input type="text" id="p_min" name="pref[presupuesto_min]" value="<?php echo esc_attr( $pref['presupuesto_min'] ?? '' ); ?>"></p>
						<p><label for="p_max">Presupuesto máximo</label><input type="text" id="p_max" name="pref[presupuesto_max]" value="<?php echo esc_attr( $pref['presupuesto_max'] ?? '' ); ?>"></p>
						<p><label for="p_mon">Moneda</label><select id="p_mon" name="pref[moneda]"><?php foreach ( Inmo_Esquema::MONEDAS as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $pref['moneda'] ?? 'UF', $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></p>
						<p><label for="p_dorm">Dormitorios mín.</label><input type="number" min="0" id="p_dorm" name="pref[dormitorios]" value="<?php echo esc_attr( $pref['dormitorios'] ?? '' ); ?>"></p>
					</div>
					<?php if ( $c ) : ?>
						<p class="description">Origen: <?php echo esc_html( Inmo_Contactos::ORIGENES[ $c->origen ] ?? $c->origen ); ?> · Creado: <?php echo esc_html( Inmo_Util::fecha( $c->creado, true ) ); ?> · Consentimiento: <?php echo esc_html( $c->consentimiento ? Inmo_Util::fecha( $c->consentimiento, true ) : 'no registrado' ); ?></p>
					<?php endif; ?>
					<p><button class="button button-primary"><?php echo $c ? 'Guardar contacto' : 'Crear contacto'; ?></button></p>
				</form>
			</section>
			<?php if ( $c ) : ?>
			<section class="inmo-caja">
				<h2>Propiedades como titular</h2>
				<?php
				$props = array_filter( Inmo_Contactos::propiedades_titular( $id ), static function ( $pid ) { return current_user_can( 'edit_post', $pid ); } );
				if ( ! $props ) {
					echo '<p>Ninguna (o sin acceso).</p>';
				} else {
					echo '<ul>';
					foreach ( $props as $pid ) {
						echo '<li><a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( get_post_meta( $pid, '_inmo_codigo', true ) . ' — ' . get_the_title( $pid ) ) . '</a> · ' . esc_html( Inmo_Esquema::ESTADOS[ get_post_meta( $pid, '_inmo_estado', true ) ] ?? '' ) . '</li>';
					}
					echo '</ul>';
				}
				?>
				<h2>Consultas por inmueble</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="inmo_consultas">
					<input type="hidden" name="_volver" value="<?php echo esc_url( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $id ) ); ?>">
					<?php wp_nonce_field( 'inmo_consultas' ); ?>
					<?php
					$grupos = array();
					foreach ( Inmo_Consultas::de_contacto( $id ) as $q ) {
						if ( ! Inmo_Permisos::puede_ver_consulta( $q ) ) {
							continue;
						}
						$q->datos = $q->datos ? json_decode( $q->datos, true ) : array();
						$grupos[ $q->propiedad_id ][] = $q;
					}
					if ( ! $grupos ) {
						echo '<p>Sin consultas.</p>';
					}
					foreach ( $grupos as $pid => $qs ) {
						echo '<h3 class="inmo-subtitulo">' . ( $pid ? '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( get_post_meta( $pid, '_inmo_codigo', true ) . ' — ' . get_the_title( $pid ) ) . '</a>' : 'Consultas generales (sin propiedad)' ) . '</h3>';
						echo '<table class="widefat striped inmo-actividad"><tbody>';
						foreach ( $qs as $q ) {
							echo '<tr><td>' . esc_html( Inmo_Util::fecha( $q->creado, true ) ) . '</td><td>' . esc_html( Inmo_Consultas::TIPOS[ $q->tipo ] ) . '</td><td>' . esc_html( wp_trim_words( $q->mensaje, 20 ) );
							if ( 'visita' === $q->tipo && $q->visita_fecha ) {
								echo '<br><strong>Cita: ' . esc_html( Inmo_Util::fecha( $q->visita_fecha, true ) ) . '</strong>';
							}
							echo '</td><td><span class="inmo-badge inmo-badge--' . esc_attr( $q->estado ) . '">' . esc_html( Inmo_Consultas::etiqueta_estado( $q ) ) . '</span></td><td>';
							Inmo_Admin_Consultas::controles_inline( $q, 'inmo_q' );
							echo '</td></tr>';
						}
						echo '</tbody></table>';
					}
					?>
				</form>
			</section>
			<section class="inmo-caja">
				<h2>Oportunidades</h2>
				<?php
				$ops = array_filter( Inmo_Oportunidades::de_contacto( $id ), array( 'Inmo_Oportunidades', 'puede_ver' ) );
				if ( $ops ) {
					echo '<ul>';
					foreach ( $ops as $o ) {
						echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=inmo-oportunidades&id=' . $o->id ) ) . '">' . esc_html( ( $o->propiedad_id ? get_post_meta( $o->propiedad_id, '_inmo_codigo', true ) : 'Búsqueda general' ) . ' — ' . Inmo_Oportunidades::ETAPAS[ $o->etapa ] ) . '</a></li>';
					}
					echo '</ul>';
				} else {
					echo '<p>Sin oportunidades.</p>';
				}
				?>
				<details><summary>Nueva oportunidad</summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="inmo_oportunidad"><input type="hidden" name="accion" value="nueva"><input type="hidden" name="id" value="0"><input type="hidden" name="contacto_id" value="<?php echo (int) $id; ?>">
						<?php wp_nonce_field( 'inmo_op_0' ); ?>
						<p><label for="no_cod">Código de propiedad (opcional)</label><input type="text" id="no_cod" name="codigo" placeholder="CP-0001"></p>
						<p><label for="no_op">Operación (si no hay propiedad)</label><select id="no_op" name="operacion"><option value="">—</option><?php foreach ( Inmo_Esquema::OPERACIONES as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></p>
						<p><button class="button">Crear oportunidad</button></p>
					</form>
				</details>
			</section>
			<section class="inmo-caja">
				<h2>Registrar consulta o nota</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="inmo_contacto">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<input type="hidden" name="op" value="registro">
					<?php wp_nonce_field( 'inmo_contacto_' . $id ); ?>
					<p><label for="r_tipo">Tipo</label><select id="r_tipo" name="r_tipo">
						<option value="nota">Nota interna</option>
						<option value="consulta">Consulta por una propiedad</option>
						<option value="visita">Visita</option>
						<option value="general">Consulta general (sin propiedad)</option>
					</select></p>
					<p><label for="r_codigo">Código de propiedad (si corresponde)</label><input type="text" id="r_codigo" name="r_codigo" placeholder="CP-0001"></p>
					<p><label for="r_texto">Detalle</label><textarea id="r_texto" name="r_texto" rows="3" class="large-text" required></textarea></p>
					<p><button class="button">Registrar</button></p>
				</form>
				<h2>Historial</h2>
				<ol class="inmo-historial">
				<?php foreach ( Inmo_Historial::de( 'contacto_id', $id, 50 ) as $h ) : ?>
					<?php
					if ( $h->propiedad_id && ! Inmo_Permisos::puede_ver_privado( $h->propiedad_id ) && ! Inmo_Permisos::ve_todo() ) {
						$q = $h->consulta_id ? Inmo_Consultas::get( $h->consulta_id ) : null;
						if ( ! $q || ! Inmo_Permisos::puede_ver_consulta( $q ) ) {
							continue;
						}
					}
					?>
					<li><time><?php echo esc_html( Inmo_Util::fecha( $h->creado, true ) ); ?></time> <strong><?php echo esc_html( Inmo_Historial::etiqueta( $h->accion ) ); ?></strong>
					<?php echo $h->propiedad_id ? '[' . esc_html( get_post_meta( $h->propiedad_id, '_inmo_codigo', true ) ) . ']' : ''; ?> — <?php echo esc_html( $h->detalle ); ?></li>
				<?php endforeach; ?>
				</ol>
			</section>
			<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function nombre_comuna( $slug ) {
		$t = get_term_by( 'slug', $slug, Inmo_Propiedades::TAX_UBICACION );
		return $t ? $t->name : $slug;
	}

	public static function guardar() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'inmo_contacto_' . $id );
		if ( ! current_user_can( 'inmo_gestionar' ) || ( $id && ! Inmo_Permisos::puede_ver_contacto( $id ) ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		$op = Inmo_Util::post( 'op' );
		if ( 'registro' === $op && $id ) {
			$tipo   = Inmo_Util::post( 'r_tipo' );
			$texto  = Inmo_Util::post_textarea( 'r_texto' );
			$codigo = Inmo_Util::post( 'r_codigo' );
			$pid    = $codigo ? Inmo_Propiedades::por_codigo( $codigo ) : 0;
			if ( $pid && ! current_user_can( 'edit_post', $pid ) && ! Inmo_Permisos::ve_todo() ) {
				$pid = 0;
			}
			if ( 'nota' === $tipo ) {
				Inmo_Historial::registrar( 'nota', $texto, array( 'contacto_id' => $id, 'propiedad_id' => $pid ) );
			} elseif ( in_array( $tipo, array( 'consulta', 'visita' ), true ) && ! $pid ) {
				Inmo_Admin::avisar( 'error', 'Indica un código de propiedad válido para registrar una consulta o visita.' );
			} else {
				Inmo_Consultas::crear( array( 'tipo' => $tipo, 'contacto_id' => $id, 'propiedad_id' => 'general' === $tipo ? 0 : $pid, 'mensaje' => $texto, 'origen' => 'manual', 'corredor_id' => get_current_user_id() ) );
				Inmo_Admin::avisar( 'success', 'Registro agregado.' );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $id ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$pref_in = isset( $_POST['pref'] ) ? (array) wp_unslash( $_POST['pref'] ) : array();
		// phpcs:enable
		$comunas = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $pref_in['comunas_texto'] ?? '' ) ) ) ) as $n ) {
			$t = get_term_by( 'slug', sanitize_title( $n ), Inmo_Propiedades::TAX_UBICACION );
			if ( $t ) {
				$comunas[] = $t->slug;
			}
		}
		$pref = array_filter(
			array(
				'operacion'       => sanitize_key( $pref_in['operacion'] ?? '' ),
				'tipos'           => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $pref_in['tipos'] ?? array() ) ), array_keys( Inmo_Esquema::TIPOS ) ) ),
				'comunas'         => $comunas,
				'presupuesto_min' => Inmo_Util::parse_numero( $pref_in['presupuesto_min'] ?? '' ),
				'presupuesto_max' => Inmo_Util::parse_numero( $pref_in['presupuesto_max'] ?? '' ),
				'moneda'          => sanitize_key( $pref_in['moneda'] ?? '' ) === 'clp' ? 'CLP' : 'UF',
				'dormitorios'     => absint( $pref_in['dormitorios'] ?? 0 ),
			)
		);
		$rut = Inmo_Util::post( 'rut' );
		if ( $rut && ! Inmo_Util::normalizar_rut( $rut ) ) {
			Inmo_Admin::avisar( 'error', 'El RUT no es válido (revisa el dígito verificador). Se conservó el RUT anterior.' );
			$rut = $id ? Inmo_Contactos::get( $id )->rut : '';
		}
		$datos = array(
			'nombre'       => Inmo_Util::post( 'nombre' ),
			'email'        => Inmo_Util::post( 'email' ),
			'telefono'     => Inmo_Util::post( 'telefono' ),
			'rut'          => $rut,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			'roles'        => isset( $_POST['roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ) ) : array(),
			'corredor_id'  => Inmo_Permisos::ve_todo() ? absint( Inmo_Util::post( 'corredor_id' ) ) : ( $id ? (int) Inmo_Contactos::get( $id )->corredor_id : get_current_user_id() ),
			'preferencias' => $pref,
		);
		if ( $id ) {
			Inmo_Contactos::actualizar( $id, $datos );
			Inmo_Historial::registrar( 'contacto', 'Datos del contacto actualizados', array( 'contacto_id' => $id ) );
		} else {
			$datos['origen'] = 'manual';
			$id              = Inmo_Contactos::upsert( $datos );
		}
		Inmo_Admin::avisar( 'success', 'Contacto guardado.' );
		wp_safe_redirect( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $id ) );
		exit;
	}
}
