<?php
/**
 * Admin: tablero de oportunidades por etapa, ficha de la oportunidad (visitas, ofertas, reserva, cierre,
 * condiciones y documentos) y pantalla de preparación/emisión/firma de documentos.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Admin_Oportunidades {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		add_action( 'admin_post_inmo_oportunidad', array( __CLASS__, 'procesar' ) );
		add_action( 'admin_post_inmo_documento_accion', array( __CLASS__, 'procesar_documento' ) );
	}

	public static function menu() {
		$h1 = add_submenu_page( 'inmo', 'Oportunidades', 'Oportunidades', 'inmo_gestionar', 'inmo-oportunidades', array( __CLASS__, 'pagina' ) );
		$h2 = add_submenu_page( 'inmo', 'Preparar documento', 'Documentos', 'inmo_gestionar', 'inmo-documento', array( __CLASS__, 'pagina_documento' ) );
		add_action( 'load-' . $h1, array( __CLASS__, 'verificar_oportunidad' ) );
		add_action( 'load-' . $h2, array( __CLASS__, 'verificar_documento' ) );
	}

	public static function verificar_oportunidad() {
		$id = absint( Inmo_Util::get( 'id' ) );
		if ( $id && ! Inmo_Oportunidades::puede_ver( Inmo_Oportunidades::get( $id ) ) ) {
			wp_die( 'No tienes acceso a esta oportunidad.', 'Acceso denegado', array( 'response' => 403, 'back_link' => true ) );
		}
	}

	public static function verificar_documento() {
		list( $prop ) = self::contexto_documento();
		if ( $prop && ! Inmo_Permisos::puede_ver_privado( $prop ) ) {
			wp_die( 'No tienes permiso para preparar documentos de esta propiedad.', 'Acceso denegado', array( 'response' => 403, 'back_link' => true ) );
		}
	}

	private static function url( $id ) {
		return admin_url( 'admin.php?page=inmo-oportunidades&id=' . (int) $id );
	}

	/* =====================================================================
	 * Tablero
	 * =================================================================== */

	public static function pagina() {
		$id = absint( Inmo_Util::get( 'id' ) );
		if ( $id ) {
			self::ficha( $id );
			return;
		}
		$op       = Inmo_Util::get( 'operacion' );
		$corredor = Inmo_Permisos::ve_todo() ? absint( Inmo_Util::get( 'corredor' ) ) : 0;
		$filas    = Inmo_Oportunidades::listar( array( 'operacion' => $op, 'corredor_id' => $corredor ) );
		$por      = array_fill_keys( array_keys( Inmo_Oportunidades::ETAPAS ), array() );
		foreach ( $filas as $f ) {
			$por[ $f->etapa ][] = $f;
		}
		?>
		<div class="wrap inmo-wrap">
			<h1>Oportunidades</h1>
			<form method="get" class="inmo-filtros">
				<input type="hidden" name="page" value="inmo-oportunidades">
				<label class="screen-reader-text" for="f-op">Operación</label>
				<select id="f-op" name="operacion"><option value="">Venta y arriendo</option>
					<?php foreach ( Inmo_Esquema::OPERACIONES as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $op, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
				</select>
				<?php if ( Inmo_Permisos::ve_todo() ) : ?>
					<label class="screen-reader-text" for="f-c">Corredor</label>
					<select id="f-c" name="corredor"><option value="">Todos los corredores</option>
						<?php foreach ( Inmo_Permisos::corredores() as $u ) : ?><option value="<?php echo (int) $u->ID; ?>" <?php selected( $corredor, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?>
					</select>
				<?php endif; ?>
				<button class="button">Filtrar</button>
				<span class="description">Las cerradas se muestran durante 90 días.</span>
			</form>
			<div class="inmo-kanban" role="list" aria-label="Etapas">
				<?php foreach ( Inmo_Oportunidades::ETAPAS as $etapa => $nombre ) : ?>
					<section class="inmo-col inmo-col--<?php echo esc_attr( $etapa ); ?>" role="listitem" aria-labelledby="col-<?php echo esc_attr( $etapa ); ?>">
						<h2 id="col-<?php echo esc_attr( $etapa ); ?>"><?php echo esc_html( $nombre ); ?> <span class="count">(<?php echo count( $por[ $etapa ] ); ?>)</span></h2>
						<?php foreach ( $por[ $etapa ] as $f ) : ?>
							<article class="inmo-card">
								<h3><a href="<?php echo esc_url( self::url( $f->id ) ); ?>"><?php echo esc_html( $f->nombre ); ?></a></h3>
								<p><?php echo $f->propiedad_id ? esc_html( get_post_meta( $f->propiedad_id, '_inmo_codigo', true ) . ' · ' . get_the_title( $f->propiedad_id ) ) : '<em>Búsqueda general</em>'; ?></p>
								<?php $val = Inmo_Oportunidades::valor_texto( $f ); ?>
								<?php if ( $val ) : ?><p class="inmo-card__valor"><?php echo esc_html( $val ); ?></p><?php endif; ?>
								<?php if ( $f->proxima_accion ) : ?><p class="inmo-card__prox">→ <?php echo esc_html( $f->proxima_accion . ( $f->proxima_fecha ? ' (' . Inmo_Util::fecha( $f->proxima_fecha ) . ')' : '' ) ); ?></p><?php endif; ?>
								<p class="description"><?php echo esc_html( get_the_author_meta( 'display_name', $f->corredor_id ) ); ?></p>
								<?php if ( in_array( $f->etapa, Inmo_Oportunidades::ABIERTAS, true ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="inmo-mover">
										<input type="hidden" name="action" value="inmo_oportunidad"><input type="hidden" name="accion" value="etapa"><input type="hidden" name="id" value="<?php echo (int) $f->id; ?>">
										<input type="hidden" name="_volver" value="<?php echo esc_url( add_query_arg( array() ) ); ?>">
										<?php wp_nonce_field( 'inmo_op_' . $f->id ); ?>
										<label class="screen-reader-text" for="mv-<?php echo (int) $f->id; ?>">Mover a</label>
										<select id="mv-<?php echo (int) $f->id; ?>" name="etapa">
											<?php foreach ( Inmo_Oportunidades::ETAPAS as $k => $l ) : if ( in_array( $k, array( 'ganada', 'perdida' ), true ) ) { continue; } ?>
												<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f->etapa, $k ); ?>><?php echo esc_html( $l ); ?></option>
											<?php endforeach; ?>
										</select>
										<button class="button button-small">Mover</button>
									</form>
								<?php elseif ( 'perdida' === $f->etapa && $f->motivo_perdida ) : ?>
									<p class="description">Motivo: <?php echo esc_html( $f->motivo_perdida ); ?></p>
								<?php endif; ?>
							</article>
						<?php endforeach; ?>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 * Ficha de la oportunidad
	 * =================================================================== */

	private static function campo_html( $name, array $c, $v ) {
		$id = 'c-' . preg_replace( '/[^a-z_]/', '', $name );
		$h  = '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $c['label'] ) . '</label>';
		if ( 'select' === $c['tipo'] ) {
			$h .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $c['opciones'] as $k => $l ) {
				$h .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $v, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			$h .= '</select>';
		} else {
			$t  = 'fecha' === $c['tipo'] ? 'date' : 'text';
			$h .= '<input type="' . $t . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '"' . ( 'numero' === $c['tipo'] ? ' inputmode="decimal"' : '' ) . '>';
		}
		if ( ! empty( $c['ayuda'] ) ) {
			$h .= '<span class="description">' . esc_html( $c['ayuda'] ) . '</span>';
		}
		return $h . '</p>';
	}

	private static function abrir( $id, $accion ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="inmo_oportunidad"><input type="hidden" name="accion" value="' . esc_attr( $accion ) . '"><input type="hidden" name="id" value="' . (int) $id . '">';
		wp_nonce_field( 'inmo_op_' . $id );
	}

	private static function ficha( $id ) {
		$o    = Inmo_Oportunidades::get( $id );
		$c    = Inmo_Contactos::get( $o->contacto_id );
		$pid  = (int) $o->propiedad_id;
		$cod  = $pid ? get_post_meta( $pid, '_inmo_codigo', true ) : '';
		$abierta = in_array( $o->etapa, Inmo_Oportunidades::ABIERTAS, true );
		?>
		<div class="wrap inmo-wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-oportunidades' ) ); ?>">← Tablero de oportunidades</a></p>
			<h1><?php echo esc_html( ( $c ? $c->nombre : 'Contacto' ) . ( $pid ? ' · ' . $cod : ' · búsqueda general' ) ); ?></h1>
			<p class="inmo-etapas" aria-label="Etapa actual">
				<?php foreach ( Inmo_Oportunidades::ETAPAS as $k => $l ) : ?>
					<span class="inmo-etapa<?php echo $k === $o->etapa ? ' is-actual' : ''; ?>"<?php echo $k === $o->etapa ? ' aria-current="step"' : ''; ?>><?php echo esc_html( $l ); ?></span>
				<?php endforeach; ?>
			</p>
			<div class="inmo-columnas inmo-columnas--ficha">
				<section class="inmo-caja">
					<h2>Resumen</h2>
					<ul class="inmo-resumen">
						<li><span>Contacto</span> <a href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $o->contacto_id ) ); ?>"><?php echo esc_html( $c ? $c->nombre : '' ); ?></a> <?php echo esc_html( $c ? $c->telefono . ' ' . $c->email : '' ); ?></li>
						<li><span>Propiedad</span> <?php echo $pid ? '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( $cod . ' — ' . get_the_title( $pid ) ) . '</a> (' . esc_html( Inmo_Esquema::ESTADOS[ get_post_meta( $pid, '_inmo_estado', true ) ] ?? '' ) . ')' : '<em>Sin propiedad</em>'; ?></li>
						<li><span>Operación</span> <?php echo esc_html( Inmo_Esquema::OPERACIONES[ $o->operacion ] ?? '—' ); ?></li>
						<li><span>Valor</span> <?php echo esc_html( Inmo_Oportunidades::valor_texto( $o ) ); ?></li>
						<li><span>Corredor</span> <?php echo esc_html( get_the_author_meta( 'display_name', $o->corredor_id ) ); ?></li>
						<li><span>Creada</span> <?php echo esc_html( Inmo_Util::fecha( $o->creado, true ) ); ?></li>
						<?php if ( $o->motivo_perdida ) : ?><li><span>Motivo pérdida</span> <?php echo esc_html( $o->motivo_perdida ); ?></li><?php endif; ?>
					</ul>
					<?php self::abrir( $id, 'datos' ); ?>
						<div class="inmo-grid">
							<p><label for="pa">Próxima acción</label><input type="text" id="pa" name="proxima_accion" value="<?php echo esc_attr( $o->proxima_accion ); ?>" placeholder="Llamar para coordinar firma"></p>
							<p><label for="pf">Fecha</label><input type="date" id="pf" name="proxima_fecha" value="<?php echo esc_attr( $o->proxima_fecha ); ?>"></p>
							<?php if ( Inmo_Permisos::ve_todo() ) : ?>
								<p><label for="pc">Corredor</label><select id="pc" name="corredor_id"><?php foreach ( Inmo_Permisos::corredores() as $u ) : ?><option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $o->corredor_id, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?></select></p>
							<?php endif; ?>
						</div>
						<h3>Condiciones de la operación</h3>
						<p class="description">Alimentan los contratos. Lo que quede vacío se toma de la ficha de la propiedad.</p>
						<div class="inmo-grid">
							<?php
							foreach ( Inmo_Oportunidades::campos_condiciones( $o->operacion ) as $k => $cc ) {
								$v = $o->condiciones[ $k ] ?? '';
								if ( 'aval_rut' === $k && $v ) {
									$v = Inmo_Util::formatear_rut( $v );
								}
								echo self::campo_html( 'condiciones[' . $k . ']', $cc, $v ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</div>
						<p><button class="button button-primary">Guardar</button></p>
					</form>
					<?php if ( $abierta ) : ?>
						<h3>Mover de etapa</h3>
						<?php self::abrir( $id, 'etapa' ); ?>
							<label class="screen-reader-text" for="et">Etapa</label>
							<select id="et" name="etapa"><?php foreach ( Inmo_Oportunidades::ETAPAS as $k => $l ) : if ( in_array( $k, array( 'ganada', 'perdida' ), true ) ) { continue; } ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $o->etapa, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
							<button class="button">Mover</button>
						</form>
					<?php else : ?>
						<?php self::abrir( $id, 'reabrir' ); ?><p><button class="button">Reabrir oportunidad</button></p></form>
					<?php endif; ?>
				</section>

				<section class="inmo-caja">
					<h2>Visitas y consultas</h2>
					<?php $qs = Inmo_Consultas::de_oportunidad( $id ); ?>
					<?php if ( $qs ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="inmo_consultas"><input type="hidden" name="_volver" value="<?php echo esc_url( self::url( $id ) ); ?>">
							<?php wp_nonce_field( 'inmo_consultas' ); ?>
							<table class="widefat striped inmo-actividad"><tbody>
							<?php foreach ( $qs as $q ) : $q->datos = $q->datos ? json_decode( $q->datos, true ) : array(); ?>
								<tr><td><?php echo esc_html( Inmo_Util::fecha( $q->creado, true ) ); ?></td><td><?php echo esc_html( Inmo_Consultas::TIPOS[ $q->tipo ] ); ?></td>
								<td><?php echo esc_html( wp_trim_words( $q->mensaje, 14 ) ); ?><?php echo $q->visita_fecha ? '<br><strong>Cita: ' . esc_html( Inmo_Util::fecha( $q->visita_fecha, true ) ) . '</strong>' : ''; ?></td>
								<td><span class="inmo-badge inmo-badge--<?php echo esc_attr( $q->estado ); ?>"><?php echo esc_html( Inmo_Consultas::etiqueta_estado( $q ) ); ?></span></td>
								<td><?php Inmo_Admin_Consultas::controles_inline( $q, 'inmo_q' ); ?></td></tr>
							<?php endforeach; ?>
							</tbody></table>
						</form>
					<?php else : ?>
						<p>Sin visitas ni consultas.</p>
					<?php endif; ?>
					<?php if ( $pid && $abierta ) : ?>
						<details><summary>Agendar visita</summary>
							<?php self::abrir( $id, 'visita' ); ?>
								<p><label for="vf">Fecha y hora</label><input type="datetime-local" id="vf" name="fecha" required></p>
								<p><label for="vn">Nota</label><input type="text" id="vn" name="nota"></p>
								<p><button class="button">Agendar (confirmada)</button></p>
							</form>
						</details>
					<?php endif; ?>

					<?php if ( $pid ) : ?>
						<h2>Ofertas</h2>
						<?php $ofs = Inmo_Ofertas::de_oportunidad( $id ); ?>
						<?php if ( $ofs ) : ?>
							<table class="widefat striped"><thead><tr><th>Fecha</th><th>Monto</th><th>Condiciones</th><th>Estado</th></tr></thead><tbody>
							<?php foreach ( $ofs as $f ) : ?>
								<tr><td><?php echo esc_html( Inmo_Util::fecha( $f->creado ) ); ?></td><td><strong><?php echo esc_html( Inmo_Util::formatear_monto( $f->monto, $f->moneda ) ); ?></strong><?php echo $f->vigencia ? '<br><span class="description">vigente hasta ' . esc_html( Inmo_Util::fecha( $f->vigencia ) ) . '</span>' : ''; ?></td>
								<td><?php echo esc_html( $f->condiciones ); ?></td>
								<td><?php self::abrir( $id, 'oferta_estado' ); ?><input type="hidden" name="oferta_id" value="<?php echo (int) $f->id; ?>">
									<label class="screen-reader-text" for="of-<?php echo (int) $f->id; ?>">Estado</label>
									<select id="of-<?php echo (int) $f->id; ?>" name="estado"><?php foreach ( Inmo_Ofertas::ESTADOS as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f->estado, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
									<button class="button button-small">Aplicar</button></form></td></tr>
							<?php endforeach; ?>
							</tbody></table>
						<?php endif; ?>
						<?php if ( $abierta ) : ?>
							<details<?php echo $ofs ? '' : ' open'; ?>><summary>Registrar oferta</summary>
								<?php self::abrir( $id, 'oferta' ); ?>
									<div class="inmo-grid">
										<p><label for="om">Monto</label><input type="text" inputmode="decimal" id="om" name="monto" required></p>
										<p><label for="omo">Moneda</label><select id="omo" name="moneda"><option value="UF" <?php selected( $o->moneda, 'UF' ); ?>>UF</option><option value="CLP" <?php selected( $o->moneda, 'CLP' ); ?>>Pesos</option></select></p>
										<p><label for="ov">Vigente hasta</label><input type="date" id="ov" name="vigencia"></p>
									</div>
									<p><label for="oc">Condiciones</label><textarea id="oc" name="condiciones" rows="2" class="large-text"></textarea></p>
									<p><button class="button">Registrar oferta</button></p>
								</form>
							</details>
							<h2>Reserva y cierre</h2>
							<?php self::abrir( $id, 'reserva' ); ?>
								<div class="inmo-grid">
									<p><label for="rm">Monto de reserva</label><input type="text" inputmode="decimal" id="rm" name="monto" value="<?php echo esc_attr( $o->condiciones['reserva_monto'] ?? '' ); ?>"></p>
									<p><label for="rmo">Moneda</label><select id="rmo" name="moneda"><option value="CLP">Pesos</option><option value="UF" <?php selected( $o->condiciones['reserva_moneda'] ?? '', 'UF' ); ?>>UF</option></select></p>
									<p><label for="rf">Fecha</label><input type="date" id="rf" name="fecha" value="<?php echo esc_attr( $o->condiciones['reserva_fecha'] ?? current_time( 'Y-m-d' ) ); ?>"></p>
								</div>
								<p><button class="button">Registrar reserva (la propiedad pasa a Reservada)</button></p>
							</form>
							<?php self::abrir( $id, 'ganada' ); ?>
								<p><label><input type="checkbox" name="cerrar_otras" value="1" checked> Cerrar como perdidas las demás oportunidades abiertas de esta propiedad</label></p>
								<p><button class="button button-primary" onclick="return confirm('¿Cerrar como ganada? La propiedad quedará <?php echo 'arriendo' === $o->operacion ? 'Arrendada' : 'Vendida'; ?> y saldrá del sitio.');">Cerrar como ganada (<?php echo 'arriendo' === $o->operacion ? 'arrendada' : 'vendida'; ?>)</button></p>
							</form>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( $abierta ) : ?>
						<?php self::abrir( $id, 'perdida' ); ?>
							<p><label for="mp">Motivo de pérdida</label><input type="text" id="mp" name="motivo" placeholder="Eligió otra propiedad" required>
							<button class="button">Cerrar como perdida</button></p>
						</form>
					<?php endif; ?>
				</section>

				<section class="inmo-caja">
					<h2>Documentos</h2>
					<?php if ( $pid ) : ?>
						<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-documento&oportunidad=' . $id ) ); ?>">Preparar documento</a></p>
						<?php self::tabla_emisiones( Inmo_Contratos::de_oportunidad( $id ) ); ?>
					<?php else : ?>
						<p>Asocia una propiedad para emitir documentos.</p>
					<?php endif; ?>
					<h2>Notas e historial</h2>
					<?php self::abrir( $id, 'nota' ); ?>
						<p><label class="screen-reader-text" for="nt">Nota</label><textarea id="nt" name="nota" rows="2" class="large-text" placeholder="Nota interna" required></textarea></p>
						<p><button class="button">Agregar nota</button></p>
					</form>
					<ol class="inmo-historial">
						<?php foreach ( Inmo_Historial::de( 'oportunidad_id', $id, 60 ) as $h ) : ?>
							<li><time><?php echo esc_html( Inmo_Util::fecha( $h->creado, true ) ); ?></time> <strong><?php echo esc_html( Inmo_Historial::etiqueta( $h->accion ) ); ?></strong> — <?php echo esc_html( $h->detalle ); ?></li>
						<?php endforeach; ?>
					</ol>
				</section>
			</div>
		</div>
		<?php
	}

	public static function tabla_emisiones( array $emisiones ) {
		if ( ! $emisiones ) {
			echo '<p>Sin documentos emitidos.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Número</th><th>Documento</th><th>Plantilla</th><th>Emisión</th><th>Firma</th><th></th></tr></thead><tbody>';
		foreach ( $emisiones as $e ) {
			echo '<tr><td><strong>' . esc_html( $e->numero ) . '</strong></td><td>' . esc_html( Inmo_Plantillas::TIPOS[ $e->tipo ] ?? $e->tipo ) . '<br><span class="description">' . esc_html( Inmo_Util::fecha( $e->creado, true ) ) . '</span></td>';
			echo '<td>v' . (int) $e->plantilla_version . '</td>';
			echo '<td><span class="inmo-badge inmo-badge--' . esc_attr( $e->estado_emision ) . '">' . esc_html( Inmo_Contratos::ESTADOS_EMISION[ $e->estado_emision ] ) . '</span></td>';
			echo '<td><span class="inmo-badge inmo-badge--firma-' . esc_attr( $e->estado_firma ) . '">' . esc_html( Inmo_Contratos::ESTADOS_FIRMA[ $e->estado_firma ] ) . '</span></td>';
			echo '<td><a href="' . esc_url( Inmo_Documentos::url( $e->documento_id ) ) . '">PDF</a> · <a href="' . esc_url( admin_url( 'admin.php?page=inmo-documento&emision=' . $e->id ) ) . '">Firmas y detalle</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function procesar() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'inmo_op_' . $id );
		$o = Inmo_Oportunidades::get( $id );
		$accion = Inmo_Util::post( 'accion' );
		if ( 'nueva' !== $accion && ! Inmo_Oportunidades::puede_ver( $o ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		$r = true;
		$msg = 'Cambios guardados.';
		switch ( $accion ) {
			case 'datos':
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanea en actualizar().
				$cond = isset( $_POST['condiciones'] ) ? (array) wp_unslash( $_POST['condiciones'] ) : array();
				Inmo_Oportunidades::actualizar( $id, array( 'condiciones' => array_map( 'sanitize_text_field', $cond ), 'proxima_accion' => Inmo_Util::post( 'proxima_accion' ), 'proxima_fecha' => Inmo_Util::post( 'proxima_fecha' ), 'corredor_id' => absint( Inmo_Util::post( 'corredor_id' ) ) ) );
				break;
			case 'etapa':
				$r   = Inmo_Oportunidades::cambiar_etapa( $id, Inmo_Util::post( 'etapa' ) );
				$msg = 'Etapa actualizada.';
				break;
			case 'reabrir':
				$r = Inmo_Oportunidades::cambiar_etapa( $id, 'calificado' );
				break;
			case 'perdida':
				$r   = Inmo_Oportunidades::cambiar_etapa( $id, 'perdida', Inmo_Util::post( 'motivo' ) );
				$msg = 'Oportunidad cerrada como perdida.';
				break;
			case 'ganada':
				$r   = Inmo_Oportunidades::cerrar_ganada( $id, ! empty( $_POST['cerrar_otras'] ) );
				$msg = 'Oportunidad cerrada como ganada.';
				break;
			case 'oferta':
				$r   = Inmo_Ofertas::crear( $id, Inmo_Util::post( 'monto' ), Inmo_Util::post( 'moneda' ), Inmo_Util::post_textarea( 'condiciones' ), Inmo_Util::post( 'vigencia' ) );
				$msg = 'Oferta registrada.';
				break;
			case 'oferta_estado':
				$f = Inmo_Ofertas::get( absint( Inmo_Util::post( 'oferta_id' ) ) );
				$r = ( $f && (int) $f->oportunidad_id === $id ) ? Inmo_Ofertas::cambiar_estado( $f->id, Inmo_Util::post( 'estado' ) ) : new WP_Error( 'x', 'Oferta no válida.' );
				break;
			case 'reserva':
				$r   = Inmo_Oportunidades::reservar( $id, Inmo_Util::post( 'monto' ), Inmo_Util::post( 'moneda' ), Inmo_Util::post( 'fecha' ) );
				$msg = 'Reserva registrada: la propiedad quedó Reservada.';
				break;
			case 'visita':
				$qid = Inmo_Consultas::crear( array( 'tipo' => 'visita', 'contacto_id' => $o->contacto_id, 'propiedad_id' => $o->propiedad_id, 'oportunidad_id' => $id, 'mensaje' => Inmo_Util::post( 'nota' ), 'origen' => 'manual', 'corredor_id' => get_current_user_id() ) );
				$r   = Inmo_Consultas::cambiar_estado( $qid, 'confirmada', Inmo_Util::post( 'fecha' ), Inmo_Util::post( 'nota' ) );
				$msg = 'Visita agendada.';
				break;
			case 'nota':
				Inmo_Historial::registrar( 'nota', Inmo_Util::post_textarea( 'nota' ), array( 'oportunidad_id' => $id, 'propiedad_id' => $o->propiedad_id, 'contacto_id' => $o->contacto_id ) );
				$msg = 'Nota agregada.';
				break;
			case 'nueva':
				$cid = absint( Inmo_Util::post( 'contacto_id' ) );
				if ( ! Inmo_Permisos::puede_ver_contacto( $cid ) ) {
					wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
				}
				$codigo = Inmo_Util::post( 'codigo' );
				$pid    = $codigo ? Inmo_Propiedades::por_codigo( $codigo ) : 0;
				if ( $codigo && ! $pid ) {
					Inmo_Admin::avisar( 'error', 'No existe una propiedad con el código ' . esc_html( $codigo ) . '.' );
					wp_safe_redirect( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $cid ) );
					exit;
				}
				$id = Inmo_Oportunidades::crear( array( 'contacto_id' => $cid, 'propiedad_id' => $pid, 'operacion' => Inmo_Util::post( 'operacion' ), 'origen' => 'manual', 'corredor_id' => $pid ? 0 : get_current_user_id() ) );
				$msg = 'Oportunidad creada.';
				break;
		}
		Inmo_Admin::avisar( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? esc_html( $r->get_error_message() ) : $msg );
		$volver = isset( $_POST['_volver'] ) ? esc_url_raw( wp_unslash( $_POST['_volver'] ) ) : self::url( $id );
		wp_safe_redirect( $volver );
		exit;
	}

	/* =====================================================================
	 * Preparar, previsualizar, emitir y firmar documentos
	 * =================================================================== */

	/** @return array [ propiedad_id, oportunidad_id ] */
	private static function contexto_documento() {
		$e = absint( Inmo_Util::get( 'emision' ) );
		if ( $e ) {
			$em = Inmo_Contratos::get( $e );
			return $em ? array( (int) $em->propiedad_id, (int) $em->oportunidad_id ) : array( 0, 0 );
		}
		$op = absint( Inmo_Util::get( 'oportunidad' ) );
		if ( $op ) {
			$o = Inmo_Oportunidades::get( $op );
			return $o ? array( (int) $o->propiedad_id, $op ) : array( 0, 0 );
		}
		return array( absint( Inmo_Util::get( 'propiedad' ) ), 0 );
	}

	public static function pagina_documento() {
		$emision = absint( Inmo_Util::get( 'emision' ) );
		if ( $emision ) {
			self::detalle_emision( $emision );
			return;
		}
		list( $pid, $opid ) = self::contexto_documento();
		echo '<div class="wrap inmo-wrap"><h1>Preparar documento</h1>';
		if ( ! $pid ) {
			echo '<p>Abre esta pantalla desde una oportunidad o desde el expediente de una propiedad.</p></div>';
			return;
		}
		$op     = Inmo_Propiedades::termino( $pid, Inmo_Propiedades::TAX_OPERACION );
		$volver = $opid ? self::url( $opid ) : get_edit_post_link( $pid );
		echo '<p><a href="' . esc_url( $volver ) . '">← Volver</a> · Propiedad <strong>' . esc_html( get_post_meta( $pid, '_inmo_codigo', true ) . ' — ' . get_the_title( $pid ) ) . '</strong>';
		if ( $opid ) {
			$o = Inmo_Oportunidades::get( $opid );
			$c = Inmo_Contactos::get( $o->contacto_id );
			echo ' · Cliente <strong>' . esc_html( $c ? $c->nombre : '' ) . '</strong>';
		}
		echo '</p>';
		$sel = absint( Inmo_Util::get( 'plantilla' ) );
		echo '<form method="get" class="inmo-filtros"><input type="hidden" name="page" value="inmo-documento">';
		echo $opid ? '<input type="hidden" name="oportunidad" value="' . (int) $opid . '">' : '<input type="hidden" name="propiedad" value="' . (int) $pid . '">';
		echo '<label for="pl">Plantilla</label> <select id="pl" name="plantilla">';
		foreach ( Inmo_Plantillas::de_tipo() as $pl ) {
			$t = get_post_meta( $pl->ID, '_inmo_tipo_doc', true );
			if ( ( ! $opid && 'oportunidad' === ( Inmo_Plantillas::CONTEXTO[ $t ] ?? '' ) ) || ( ( Inmo_Plantillas::OPERACION[ $t ] ?? '' ) && Inmo_Plantillas::OPERACION[ $t ] !== $op ) ) {
				continue;
			}
			echo '<option value="' . (int) $pl->ID . '"' . selected( $sel, $pl->ID, false ) . '>' . esc_html( $pl->post_title . ' (v' . (int) get_post_meta( $pl->ID, '_inmo_version', true ) . ')' ) . '</option>';
		}
		echo '</select> <button class="button">Vista previa</button></form>';

		if ( $sel ) {
			$r = Inmo_Contratos::previsualizar( $sel, $pid, $opid );
			if ( is_wp_error( $r ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $r->get_error_message() ) . '</p></div>';
			} else {
				if ( $r['faltan'] ) {
					echo '<div class="notice notice-error inline inmo-faltantes" role="alert"><p><strong>No se puede emitir todavía. Falta:</strong></p><ul>';
					foreach ( $r['faltan'] as $l ) {
						echo '<li>' . esc_html( $l ) . '</li>';
					}
					echo '</ul><p>Completa estos datos en la ficha del contacto, la ficha de la propiedad o las condiciones de la oportunidad.</p></div>';
				} else {
					echo '<div class="notice notice-success inline"><p>Todos los datos están completos.</p></div>';
				}
				echo '<h2>Vista previa · ' . esc_html( $r['titulo'] ) . ' · plantilla v' . (int) $r['version'] . '</h2>';
				echo '<div class="inmo-previa" id="inmo-previa">' . wp_kses_post( $r['html'] ) . '</div>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="inmo_documento_accion"><input type="hidden" name="accion" value="emitir">';
				echo '<input type="hidden" name="plantilla" value="' . (int) $sel . '"><input type="hidden" name="propiedad" value="' . (int) $pid . '"><input type="hidden" name="oportunidad" value="' . (int) $opid . '">';
				wp_nonce_field( 'inmo_doc_' . $pid );
				echo '<p><button class="button button-primary button-large" id="inmo-emitir"' . disabled( (bool) $r['faltan'], true, false ) . '>Emitir PDF</button> <span class="description">Al emitir se asigna un número y se congela el texto: cambios posteriores en la plantilla no lo alteran.</span></p></form>';
			}
		}
		echo '</div>';
	}

	private static function detalle_emision( $id ) {
		$e = Inmo_Contratos::get( $id );
		if ( ! $e ) {
			echo '<div class="wrap"><p>Documento no encontrado.</p></div>';
			return;
		}
		$integro = Inmo_Contratos::integro( $e );
		echo '<div class="wrap inmo-wrap"><h1>' . esc_html( $e->titulo ) . '</h1>';
		echo '<p><a href="' . esc_url( $e->oportunidad_id ? self::url( $e->oportunidad_id ) : get_edit_post_link( $e->propiedad_id ) ) . '">← Volver</a></p>';
		echo '<ul class="inmo-resumen">';
		echo '<li><span>Emitido</span> ' . esc_html( Inmo_Util::fecha( $e->creado, true ) . ' por ' . get_the_author_meta( 'display_name', $e->user_id ) ) . '</li>';
		echo '<li><span>Plantilla</span> ' . esc_html( get_the_title( $e->plantilla_id ) . ' · versión ' . $e->plantilla_version ) . '</li>';
		echo '<li><span>Emisión</span> ' . esc_html( Inmo_Contratos::ESTADOS_EMISION[ $e->estado_emision ] . ( $e->motivo_anulacion ? ' — ' . $e->motivo_anulacion : '' ) ) . '</li>';
		echo '<li><span>Firma</span> ' . esc_html( Inmo_Contratos::ESTADOS_FIRMA[ $e->estado_firma ] ) . '</li>';
		echo '<li><span>Integridad</span> ' . ( $integro ? '✔ El PDF no ha cambiado desde su emisión' : '<strong>✖ El archivo no coincide con su huella</strong>' ) . ' <code>' . esc_html( substr( $e->sha256, 0, 16 ) ) . '…</code></li>';
		echo '</ul><p><a class="button" href="' . esc_url( Inmo_Documentos::url( $e->documento_id ) ) . '">Descargar PDF emitido</a></p>';

		echo '<h2>Firmantes</h2><p class="description">Seguimiento manual hasta conectar el proveedor de firma electrónica (Entrega 3). La firma no cierra la operación: el cierre se registra en la oportunidad.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Rol</th><th>Nombre</th><th>Estado</th><th>Actualizar</th></tr></thead><tbody>';
		foreach ( $e->firmantes as $i => $f ) {
			echo '<tr><td>' . esc_html( $f['rol'] ) . '</td><td>' . esc_html( $f['nombre'] ) . '</td><td>' . esc_html( $f['estado'] . ( $f['fecha'] ? ' · ' . Inmo_Util::fecha( $f['fecha'], true ) : '' ) );
			if ( ! empty( $f['documento_id'] ) ) {
				echo ' · <a href="' . esc_url( Inmo_Documentos::url( $f['documento_id'] ) ) . '">copia firmada</a>';
			}
			echo '</td><td>';
			if ( 'emitido' === $e->estado_emision ) {
				echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="inmo-ctrl">';
				echo '<input type="hidden" name="action" value="inmo_documento_accion"><input type="hidden" name="accion" value="firma"><input type="hidden" name="emision" value="' . (int) $e->id . '"><input type="hidden" name="indice" value="' . (int) $i . '">';
				wp_nonce_field( 'inmo_doc_' . $e->propiedad_id );
				echo '<label class="screen-reader-text" for="fe-' . (int) $i . '">Estado</label><select id="fe-' . (int) $i . '" name="estado"><option value="firmado">Firmado</option><option value="rechazado">Rechazado</option><option value="pendiente">Pendiente</option></select>';
				echo '<label class="screen-reader-text" for="ff-' . (int) $i . '">Copia firmada (PDF)</label><input type="file" id="ff-' . (int) $i . '" name="archivo" accept=".pdf,.jpg,.png">';
				echo '<button class="button button-small">Registrar</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( 'emitido' === $e->estado_emision ) {
			echo '<h2>Anular</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="inmo_documento_accion"><input type="hidden" name="accion" value="anular"><input type="hidden" name="emision" value="' . (int) $e->id . '">';
			wp_nonce_field( 'inmo_doc_' . $e->propiedad_id );
			echo '<p><label for="an">Motivo</label> <input type="text" id="an" name="motivo" class="regular-text" required> <button class="button">Anular documento</button></p><p class="description">El PDF anulado se conserva en el expediente.</p></form>';
		}
		echo '<h2>Texto emitido</h2><div class="inmo-previa">' . wp_kses_post( preg_replace( '#^.*<body>|</body>.*$#s', '', $e->html ) ) . '</div></div>';
	}

	public static function procesar_documento() {
		$accion = Inmo_Util::post( 'accion' );
		if ( 'emitir' === $accion ) {
			$pid  = absint( Inmo_Util::post( 'propiedad' ) );
			$opid = absint( Inmo_Util::post( 'oportunidad' ) );
			$pl   = absint( Inmo_Util::post( 'plantilla' ) );
			check_admin_referer( 'inmo_doc_' . $pid );
			if ( $opid ) {
				$o = Inmo_Oportunidades::get( $opid );
				if ( ! $o || (int) $o->propiedad_id !== $pid || ! Inmo_Oportunidades::puede_ver( $o ) ) {
					wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
				}
			}
			$r = Inmo_Contratos::emitir( $pl, $pid, $opid );
			if ( is_wp_error( $r ) ) {
				Inmo_Admin::avisar( 'error', esc_html( $r->get_error_message() ) );
				wp_safe_redirect( admin_url( 'admin.php?page=inmo-documento&plantilla=' . $pl . ( $opid ? '&oportunidad=' . $opid : '&propiedad=' . $pid ) ) );
				exit;
			}
			$e = Inmo_Contratos::get( $r );
			Inmo_Admin::avisar( 'success', 'Documento <strong>' . esc_html( $e->numero ) . '</strong> emitido.' );
			wp_safe_redirect( admin_url( 'admin.php?page=inmo-documento&emision=' . $r ) );
			exit;
		}
		$eid = absint( Inmo_Util::post( 'emision' ) );
		$e   = Inmo_Contratos::get( $eid );
		check_admin_referer( 'inmo_doc_' . ( $e ? $e->propiedad_id : 0 ) );
		if ( ! $e || ! Inmo_Permisos::puede_ver_privado( $e->propiedad_id ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		if ( 'firma' === $accion ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$r = Inmo_Contratos::registrar_firma( $eid, absint( Inmo_Util::post( 'indice' ) ), Inmo_Util::post( 'estado' ), ! empty( $_FILES['archivo']['name'] ) ? $_FILES['archivo'] : null );
		} else {
			$r = Inmo_Contratos::anular( $eid, Inmo_Util::post( 'motivo' ) );
		}
		Inmo_Admin::avisar( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? esc_html( $r->get_error_message() ) : 'Actualizado.' );
		wp_safe_redirect( admin_url( 'admin.php?page=inmo-documento&emision=' . $eid ) );
		exit;
	}
}
