<?php
/**
 * Ficha interna de la propiedad (pantalla de edición) y listado.
 * Reúne en un solo lugar: estado y publicación, datos, características, fotos,
 * titulares y mandato (privado), documentos, consultas/visitas e historial.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Admin_Propiedad {

	private static $guardando = false;

	public static function init() {
		add_action( 'add_meta_boxes_' . INMO_CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . INMO_CPT, array( __CLASS__, 'guardar' ), 10, 2 );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_multipart' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'datos_post' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'placeholder_titulo' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'despues_titulo' ) );
		// Listado.
		add_filter( 'manage_' . INMO_CPT . '_posts_columns', array( __CLASS__, 'columnas' ) );
		add_action( 'manage_' . INMO_CPT . '_posts_custom_column', array( __CLASS__, 'columna' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'listado_query' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filtros_listado' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'acciones_fila' ), 10, 2 );
		add_filter( 'views_edit-' . INMO_CPT, array( __CLASS__, 'vistas' ) );
		add_filter( 'bulk_actions-edit-' . INMO_CPT, array( __CLASS__, 'acciones_masivas' ) );
	}

	/* =====================================================================
	 * Pantalla de edición
	 * =================================================================== */

	public static function form_multipart( $post ) {
		if ( INMO_CPT === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	public static function placeholder_titulo( $t, $post ) {
		return INMO_CPT === $post->post_type ? 'Título del aviso (si lo dejas vacío se genera: «Departamento en arriendo en Ñuñoa»)' : $t;
	}

	public static function despues_titulo( $post ) {
		if ( INMO_CPT !== $post->post_type ) {
			return;
		}
		echo '<p class="inmo-ayuda-editor"><strong>Descripción pública</strong> (aparece en la ficha del sitio). No incluyas la dirección exacta ni datos del propietario.</p>';
	}

	public static function meta_boxes( $post ) {
		remove_meta_box( 'submitdiv', INMO_CPT, 'side' );
		remove_meta_box( 'authordiv', INMO_CPT, 'normal' );
		remove_meta_box( 'postimagediv', INMO_CPT, 'side' );
		add_meta_box( 'inmo_estado', 'Estado y publicación', array( __CLASS__, 'mb_estado' ), INMO_CPT, 'side', 'high' );
		add_meta_box( 'inmo_resumen', 'Resumen de la ficha', array( __CLASS__, 'mb_resumen' ), INMO_CPT, 'side', 'default' );
		add_meta_box( 'inmo_principal', 'Operación, precio y ubicación', array( __CLASS__, 'mb_principal' ), INMO_CPT, 'normal', 'high' );
		add_meta_box( 'inmo_caracteristicas', 'Características', array( __CLASS__, 'mb_caracteristicas' ), INMO_CPT, 'normal', 'high' );
		add_meta_box( 'inmo_galeria', 'Fotos autorizadas (galería pública)', array( __CLASS__, 'mb_galeria' ), INMO_CPT, 'normal', 'default' );
		if ( 'auto-draft' === $post->post_status || Inmo_Permisos::puede_ver_privado( $post->ID ) ) {
			add_meta_box( 'inmo_captacion', 'Captación: titulares, mandato y documentos (privado)', array( __CLASS__, 'mb_captacion' ), INMO_CPT, 'normal', 'default' );
		}
		if ( 'auto-draft' !== $post->post_status && Inmo_Permisos::puede_ver_privado( $post->ID ) ) {
			add_meta_box( 'inmo_expediente', 'Expediente: oportunidades, ofertas, documentos emitidos y antecedentes (privado)', array( __CLASS__, 'mb_expediente' ), INMO_CPT, 'normal', 'default' );
		}
		add_meta_box( 'inmo_actividad', 'Consultas, visitas e historial', array( __CLASS__, 'mb_actividad' ), INMO_CPT, 'normal', 'low' );
	}

	public static function mb_estado( $post ) {
		wp_nonce_field( 'inmo_guardar_' . $post->ID, 'inmo_nonce' );
		$estado = get_post_meta( $post->ID, '_inmo_estado', true );
		$estado = $estado ? $estado : 'borrador';
		$vis    = get_post_meta( $post->ID, '_inmo_visibilidad', true );
		$vis    = $vis ? $vis : 'publica';
		$op     = Inmo_Propiedades::termino( $post->ID, Inmo_Propiedades::TAX_OPERACION );
		$aprobar = current_user_can( 'inmo_aprobar' );
		$ya_aprobada = ! in_array( $estado, array( 'borrador', 'en_revision' ), true );
		$codigo = get_post_meta( $post->ID, '_inmo_codigo', true );
		?>
		<div class="inmo-estado-box">
			<p class="inmo-codigo">Código: <strong><?php echo esc_html( $codigo ? $codigo : 'se asigna al guardar' ); ?></strong></p>
			<p><label for="inmo_estado"><strong>Estado comercial</strong></label><br>
			<select name="inmo_estado" id="inmo_estado" class="widefat">
				<?php
				foreach ( Inmo_Esquema::estados_para( $op ) as $k => $lbl ) :
					$bloqueado = ! $aprobar && ! $ya_aprobada && in_array( $k, Inmo_Esquema::ESTADOS_APROBADOS, true );
					?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $estado, $k ); ?> <?php disabled( $bloqueado ); ?>><?php echo esc_html( $lbl . ( $bloqueado ? ' (requiere aprobación)' : '' ) ); ?></option>
				<?php endforeach; ?>
			</select></p>
			<p><label for="inmo_visibilidad"><strong>Visibilidad</strong></label><br>
			<select name="inmo_visibilidad" id="inmo_visibilidad" class="widefat">
				<?php foreach ( Inmo_Esquema::VISIBILIDAD as $k => $lbl ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $vis, $k ); ?>><?php echo esc_html( $lbl ); ?></option>
				<?php endforeach; ?>
			</select></p>
			<?php if ( Inmo_Permisos::ve_todo() ) : ?>
				<p><label for="inmo_corredor"><strong>Corredor responsable</strong></label><br>
				<select name="inmo_corredor" id="inmo_corredor" class="widefat">
					<?php foreach ( Inmo_Permisos::corredores() as $u ) : ?>
						<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $post->post_author, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
					<?php endforeach; ?>
				</select></p>
			<?php else : ?>
				<p>Corredor responsable: <strong><?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?></strong></p>
			<?php endif; ?>
			<p><label><input type="checkbox" name="inmo_destacado" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_inmo_destacado', true ) ); ?>> Destacar en la portada</label></p>
			<p class="description">El estado de publicación del sitio se deriva de estos campos: solo <em>Disponible</em> o <em>Reservada</em> con visibilidad pública aparecen en la web.
			<?php if ( ! $aprobar ) : ?> Las captaciones nuevas quedan <strong>en revisión</strong> hasta que administración las apruebe.<?php endif; ?></p>
			<div class="inmo-botones">
				<?php if ( $aprobar && ! $ya_aprobada ) : ?>
					<button type="submit" name="inmo_aprobar" value="1" class="button button-primary button-large">Aprobar y publicar</button>
				<?php elseif ( ! $aprobar && 'borrador' === $estado ) : ?>
					<button type="submit" name="inmo_enviar_revision" value="1" class="button button-primary button-large">Enviar a revisión</button>
				<?php endif; ?>
				<button type="submit" name="save" value="1" class="button button-large">Guardar ficha</button>
			</div>
		</div>
		<?php
	}

	public static function mb_resumen( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>Guarda la ficha para ver el resumen.</p>';
			return;
		}
		$priv      = Inmo_Permisos::puede_ver_privado( $post->ID );
		$titulares = $priv ? Inmo_Contactos::titulares( $post->ID ) : array();
		$consultas = Inmo_Consultas::de_propiedad( $post->ID );
		$n         = array( 'consulta' => 0, 'visita' => 0, 'visita_pend' => 0 );
		$personas  = array();
		foreach ( $consultas as $q ) {
			if ( 'visita' === $q->tipo ) {
				$n['visita']++;
				if ( 'pendiente' === $q->estado ) {
					$n['visita_pend']++;
				}
			} elseif ( 'consulta' === $q->tipo ) {
				$n['consulta']++;
			}
			if ( in_array( $q->tipo, array( 'consulta', 'visita' ), true ) ) {
				$personas[ $q->contacto_id ] = $q->nombre;
			}
		}
		$docs     = $priv ? Inmo_Documentos::de_propiedad( $post->ID ) : array();
		$faltan   = Inmo_Propiedades::faltantes_para_publicar( $post->ID );
		$publicada = 'publish' === $post->post_status;
		?>
		<ul class="inmo-resumen">
			<li><span>Dueño/a</span> <?php echo $priv ? ( $titulares ? esc_html( implode( ', ', wp_list_pluck( $titulares, 'nombre' ) ) ) : '<em>Sin titular</em>' ) : '<em>Restringido</em>'; ?></li>
			<li><span>Interesados</span> <?php echo count( $personas ); ?> persona(s) · <?php echo (int) $n['consulta']; ?> consulta(s)</li>
			<li><span>Visitas</span> <?php echo (int) $n['visita']; ?><?php echo $n['visita_pend'] ? ' · <strong>' . (int) $n['visita_pend'] . ' por confirmar</strong>' : ''; ?></li>
			<?php $ofs = $priv ? Inmo_Ofertas::de_propiedad( $post->ID ) : array(); $ops = $priv ? Inmo_Oportunidades::de_propiedad( $post->ID, true ) : array(); ?>
			<li><span>Oportunidades</span> <?php echo $priv ? count( $ops ) . ' abierta(s)' : '<em>Restringido</em>'; ?></li>
			<li><span>Ofertas</span> <?php echo $priv ? count( $ofs ) . ( $ofs ? ' · última ' . esc_html( Inmo_Util::formatear_monto( $ofs[0]->monto, $ofs[0]->moneda ) . ' (' . Inmo_Ofertas::ESTADOS[ $ofs[0]->estado ] . ')' ) : '' ) : '<em>Restringido</em>'; ?></li>
			<li><span>Documentos</span> <?php echo $priv ? count( $docs ) : '<em>Restringido</em>'; ?></li>
			<li><span>Canales</span>
				<span class="inmo-canal <?php echo $publicada ? 'is-on' : 'is-off'; ?>">Sitio web: <?php echo $publicada ? 'publicado' : 'no publicado'; ?></span>
				<span class="inmo-canal is-off" title="Conectores en la Entrega 3">Yapo / Portal Inmobiliario: Entrega 3</span>
			</li>
		</ul>
		<?php if ( $faltan ) : ?>
			<p class="inmo-faltan"><strong>Falta para publicar:</strong> <?php echo esc_html( implode( ', ', $faltan ) ); ?>.</p>
		<?php endif; ?>
		<?php if ( $publicada ) : ?>
			<p><a href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener">Ver ficha pública ↗</a></p>
		<?php endif; ?>
		<?php
	}

	private static function input_campo( $name, array $c, $valor, $id = null ) {
		$id = $id ? $id : $name;
		switch ( $c['tipo'] ) {
			case 'select':
				$h = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"><option value="">—</option>';
				foreach ( $c['opciones'] as $k => $l ) {
					$h .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $valor, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
				}
				return $h . '</select>';
			case 'fecha':
				return '<input type="date" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $valor ) . '">';
			case 'textarea':
				return '<textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" rows="3" class="large-text">' . esc_textarea( $valor ) . '</textarea>';
			case 'numero':
			case 'coord':
				$step = isset( $c['step'] ) ? $c['step'] : ( 'coord' === $c['tipo'] ? 'any' : '1' );
				return '<input type="number" step="' . esc_attr( $step ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $valor ) . '">';
			default:
				return '<input type="text" class="regular-text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $valor ) . '">';
		}
	}

	private static function data_aplica( array $c ) {
		return ' data-tipos="' . esc_attr( empty( $c['tipos'] ) ? '' : implode( ' ', $c['tipos'] ) ) . '" data-ops="' . esc_attr( empty( $c['operaciones'] ) ? '' : implode( ' ', $c['operaciones'] ) ) . '"';
	}

	public static function mb_principal( $post ) {
		$tipo   = Inmo_Propiedades::termino( $post->ID, Inmo_Propiedades::TAX_TIPO );
		$op     = Inmo_Propiedades::termino( $post->ID, Inmo_Propiedades::TAX_OPERACION );
		$comuna = Inmo_Propiedades::comuna( $post->ID );
		$moneda = get_post_meta( $post->ID, '_inmo_moneda', true );
		$prec   = get_post_meta( $post->ID, '_inmo_precision', true );
		$prec   = $prec ? $prec : Inmo_Ajustes::get( 'precision_defecto' );
		$priv   = ( 'auto-draft' === $post->post_status || Inmo_Permisos::puede_ver_privado( $post->ID ) ) ? Inmo_Privado::get( $post->ID ) : null;
		?>
		<div class="inmo-grid" id="inmo-ficha" data-tipo="<?php echo esc_attr( $tipo ); ?>" data-op="<?php echo esc_attr( $op ); ?>">
			<p><label for="inmo_tipo">Tipo de propiedad *</label>
				<select name="inmo_tipo" id="inmo_tipo" required><option value="">Selecciona…</option>
				<?php foreach ( Inmo_Esquema::TIPOS as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $tipo, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?></select></p>
			<fieldset><legend>Operación *</legend>
				<?php foreach ( Inmo_Esquema::OPERACIONES as $k => $l ) : ?>
					<label class="inmo-inline"><input type="radio" name="inmo_operacion" value="<?php echo esc_attr( $k ); ?>" <?php checked( $op, $k ); ?>> <?php echo esc_html( $l ); ?></label>
				<?php endforeach; ?>
			</fieldset>
			<p><label for="inmo_precio" id="inmo_precio_label" data-venta="Precio solicitado *" data-arriendo="Renta mensual *"><?php echo esc_html( Inmo_Esquema::etiqueta_precio( $op ) ); ?> *</label>
				<input type="text" inputmode="decimal" name="inmo_precio" id="inmo_precio" value="<?php echo esc_attr( get_post_meta( $post->ID, '_inmo_precio', true ) ); ?>" placeholder="5200 o 650000">
				<select name="inmo_moneda" id="inmo_moneda" aria-label="Moneda">
					<?php foreach ( Inmo_Esquema::MONEDAS as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $moneda ? $moneda : Inmo_Esquema::moneda_defecto( $op ), $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="description">Se publica en la moneda original. La equivalencia usa la UF de Ajustes.</span></p>
			<?php foreach ( Inmo_Esquema::campos() as $k => $c ) : ?>
				<?php if ( 'precio' !== $c['grupo'] && 'arriendo' !== $c['grupo'] ) { continue; } ?>
				<p class="inmo-cond"<?php echo self::data_aplica( $c ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><label for="inmo_c_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $c['label'] ); ?></label>
				<?php echo self::input_campo( 'inmo_c[' . $k . ']', $c, get_post_meta( $post->ID, '_inmo_' . $k, true ), 'inmo_c_' . $k ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php endforeach; ?>
			<p class="inmo-cond" data-tipos="" data-ops=""><em>Ofertas, reserva y cierre se registran en cada oportunidad (ver «Expediente»).</em></p>

			<h3 class="inmo-subtitulo">Ubicación</h3>
			<p><label for="inmo_comuna">Comuna *</label>
				<select name="inmo_comuna" id="inmo_comuna"><option value="">Selecciona…</option>
				<?php foreach ( Inmo_Busqueda::comunas( false, true ) as $region => $comunas ) : ?>
					<optgroup label="<?php echo esc_attr( $region ); ?>">
					<?php foreach ( $comunas as $id => $nombre ) : ?>
						<option value="<?php echo (int) $id; ?>" <?php selected( $comuna ? $comuna->term_id : 0, $id ); ?>><?php echo esc_html( $nombre ); ?></option>
					<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?></select></p>
			<p><label for="inmo_sector">Sector / barrio (público)</label>
				<input type="text" class="regular-text" name="inmo_sector" id="inmo_sector" value="<?php echo esc_attr( get_post_meta( $post->ID, '_inmo_sector', true ) ); ?>"></p>
			<p><label for="inmo_precision">Ubicación en el sitio</label>
				<select name="inmo_precision" id="inmo_precision">
				<?php foreach ( Inmo_Esquema::PRECISION as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $prec, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?></select></p>
			<?php if ( $priv ) : ?>
				<div class="inmo-privado">
					<p class="inmo-candado">🔒 Datos privados: no se muestran en el sitio.</p>
					<?php foreach ( array( 'direccion', 'unidad', 'rol_sii', 'lat', 'lng' ) as $k ) : $c = Inmo_Privado::campos()[ $k ]; ?>
						<p><label for="inmo_p_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $c['label'] ); ?></label>
						<?php echo self::input_campo( 'inmo_p[' . $k . ']', $c, $priv[ $k ], 'inmo_p_' . $k ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endforeach; ?>
					<p class="description">Coordenadas en grados decimales (p. ej. -33.4569, -70.5980). Con «Aproximada» el sitio muestra un punto desplazado ~150–400 m.</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function mb_caracteristicas( $post ) {
		echo '<div class="inmo-grid">';
		foreach ( Inmo_Esquema::campos() as $k => $c ) {
			if ( 'caracteristicas' !== $c['grupo'] ) {
				continue;
			}
			echo '<p class="inmo-cond"' . self::data_aplica( $c ) . '><label for="inmo_c_' . esc_attr( $k ) . '">' . esc_html( $c['label'] . ( isset( $c['unidad'] ) ? ' (' . $c['unidad'] . ')' : '' ) ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo self::input_campo( 'inmo_c[' . $k . ']', $c, get_post_meta( $post->ID, '_inmo_' . $k, true ), 'inmo_c_' . $k ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</p>';
		}
		echo '</div><fieldset class="inmo-equip"><legend>Equipamiento</legend>';
		$sel   = (array) get_post_meta( $post->ID, '_inmo_equipamiento', true );
		$todos = array();
		foreach ( Inmo_Esquema::TIPOS as $t => $l ) {
			foreach ( Inmo_Esquema::equipamiento( $t ) as $k => $lbl ) {
				$todos[ $k ]['label']   = $lbl;
				$todos[ $k ]['tipos'][] = $t;
			}
		}
		foreach ( $todos as $k => $e ) {
			echo '<label class="inmo-inline inmo-cond" data-tipos="' . esc_attr( implode( ' ', $e['tipos'] ) ) . '" data-ops=""><input type="checkbox" name="inmo_equip[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $sel, true ), true, false ) . '> ' . esc_html( $e['label'] ) . '</label>';
		}
		echo '</fieldset><input type="hidden" name="inmo_equip_enviado" value="1">';
	}

	public static function mb_galeria( $post ) {
		$ids = Inmo_Propiedades::galeria( $post->ID );
		?>
		<p class="description">La primera foto es la portada. Arrastra para ordenar. Solo las fotos de esta galería se muestran en el sitio.</p>
		<ul class="inmo-galeria" id="inmo-galeria">
			<?php foreach ( $ids as $id ) : ?>
				<li data-id="<?php echo (int) $id; ?>"><?php echo wp_get_attachment_image( $id, 'thumbnail' ); ?><button type="button" class="inmo-quitar" aria-label="Quitar foto">×</button></li>
			<?php endforeach; ?>
		</ul>
		<input type="hidden" name="inmo_galeria" id="inmo_galeria_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
		<p><button type="button" class="button" id="inmo-agregar-fotos">Agregar fotos</button></p>
		<?php
		if ( ! Inmo_Permisos::puede_ver_privado( $post->ID ) ) {
			return;
		}
		$pend = Inmo_Documentos::de_propiedad( $post->ID, 'foto_propietario', 'pendiente' );
		if ( $pend ) {
			echo '<h4>Fotos enviadas por el propietario (pendientes de autorización)</h4><ul class="inmo-galeria inmo-galeria--pend">';
			foreach ( $pend as $d ) {
				echo '<li><img src="' . esc_url( Inmo_Documentos::url( $d->id, true ) ) . '" alt="" width="150" height="150" loading="lazy">';
				echo '<label><input type="checkbox" name="inmo_autorizar_foto[]" value="' . (int) $d->id . '"> Autorizar</label>';
				echo '<label><input type="checkbox" name="inmo_descartar_foto[]" value="' . (int) $d->id . '"> Descartar</label></li>';
			}
			echo '</ul><p class="description">Las fotos autorizadas pasan a la biblioteca de medios y se agregan al final de la galería al guardar.</p>';
		}
	}

	public static function mb_captacion( $post ) {
		$priv      = Inmo_Privado::get( $post->ID );
		$titulares = Inmo_Contactos::titulares( $post->ID );
		?>
		<h3 class="inmo-subtitulo">Titulares</h3>
		<?php if ( $titulares ) : ?>
			<table class="widefat striped"><thead><tr><th>Nombre</th><th>RUT</th><th>Correo</th><th>Teléfono</th><th>Quitar</th></tr></thead><tbody>
			<?php foreach ( $titulares as $t ) : ?>
				<tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $t->id ) ); ?>"><?php echo esc_html( $t->nombre ); ?></a></td>
				<td><?php echo esc_html( Inmo_Util::formatear_rut( $t->rut ) ); ?></td><td><?php echo esc_html( $t->email ); ?></td><td><?php echo esc_html( $t->telefono ); ?></td>
				<td><input type="checkbox" name="inmo_quitar_titular[]" value="<?php echo (int) $t->id; ?>" aria-label="Quitar a <?php echo esc_attr( $t->nombre ); ?>"></td></tr>
			<?php endforeach; ?></tbody></table>
		<?php else : ?>
			<p><em>Sin titulares registrados.</em></p>
		<?php endif; ?>
		<details class="inmo-agregar"><summary>Agregar titular</summary>
			<p><label for="inmo_titular_existente">Contacto existente</label>
			<select name="inmo_titular_existente" id="inmo_titular_existente"><option value="">—</option>
			<?php
			list( $contactos ) = Inmo_Contactos::listar( array( 'por_pagina' => 300 ) );
			foreach ( $contactos as $c ) :
				?>
				<option value="<?php echo (int) $c->id; ?>"><?php echo esc_html( $c->nombre . ( $c->email ? ' · ' . $c->email : '' ) ); ?></option>
			<?php endforeach; ?></select></p>
			<p><strong>o nuevo contacto:</strong></p>
			<div class="inmo-grid">
				<p><label for="inmo_nt_nombre">Nombre</label><input type="text" id="inmo_nt_nombre" name="inmo_nt[nombre]"></p>
				<p><label for="inmo_nt_rut">RUT</label><input type="text" id="inmo_nt_rut" name="inmo_nt[rut]" placeholder="12.345.678-5"></p>
				<p><label for="inmo_nt_email">Correo</label><input type="email" id="inmo_nt_email" name="inmo_nt[email]"></p>
				<p><label for="inmo_nt_tel">Teléfono</label><input type="tel" id="inmo_nt_tel" name="inmo_nt[telefono]"></p>
			</div>
		</details>

		<h3 class="inmo-subtitulo">Mandato y condiciones</h3>
		<div class="inmo-grid">
			<?php foreach ( array( 'mandato_tipo', 'exclusividad', 'comision_pct', 'comision_detalle', 'captacion_vence', 'precio_minimo' ) as $k ) : $c = Inmo_Privado::campos()[ $k ]; ?>
				<p><label for="inmo_p_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $c['label'] ); ?></label>
				<?php echo self::input_campo( 'inmo_p[' . $k . ']', $c, $priv[ $k ], 'inmo_p_' . $k ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php endforeach; ?>
		</div>
		<p><label for="inmo_p_notas"><?php echo esc_html( Inmo_Privado::campos()['notas']['label'] ); ?></label>
		<?php echo self::input_campo( 'inmo_p[notas]', Inmo_Privado::campos()['notas'], $priv['notas'], 'inmo_p_notas' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>

		<h3 class="inmo-subtitulo">Documentos privados</h3>
		<?php $docs = 'auto-draft' === $post->post_status ? array() : Inmo_Documentos::de_propiedad( $post->ID ); ?>
		<?php if ( $docs ) : ?>
			<table class="widefat striped"><thead><tr><th>Tipo</th><th>Archivo</th><th>Estado</th><th>Subido</th></tr></thead><tbody>
			<?php foreach ( $docs as $d ) : ?>
				<tr><td><?php echo esc_html( Inmo_Documentos::TIPOS[ $d->tipo ] ?? $d->tipo ); ?></td>
				<td><a href="<?php echo esc_url( Inmo_Documentos::url( $d->id ) ); ?>"><?php echo esc_html( $d->nombre ); ?></a> <span class="description">(<?php echo esc_html( size_format( $d->bytes ) ); ?>)</span></td>
				<td><?php echo esc_html( $d->estado ); ?></td><td><?php echo esc_html( Inmo_Util::fecha( $d->creado, true ) ); ?></td></tr>
			<?php endforeach; ?></tbody></table>
		<?php endif; ?>
		<p><label for="inmo_doc_tipo">Subir documento</label>
			<select name="inmo_doc_tipo" id="inmo_doc_tipo">
			<?php foreach ( Inmo_Documentos::TIPOS as $k => $l ) : if ( 'foto_propietario' === $k ) { continue; } ?>
				<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option>
			<?php endforeach; ?></select>
			<input type="file" name="inmo_doc" accept=".pdf,.jpg,.jpeg,.png,.docx" aria-label="Archivo del documento"></p>
		<p class="description">PDF, JPG, PNG o DOCX hasta 10 MB. Se guardan fuera de la biblioteca de medios y solo los abren administración y el corredor responsable.</p>

		<h3 class="inmo-subtitulo">Invitación al propietario</h3>
		<?php
		$inv = 'auto-draft' === $post->post_status ? null : Inmo_Invitaciones::ultima( $post->ID );
		if ( $inv ) {
			if ( $inv->usada ) {
				echo '<p>Completada el ' . esc_html( Inmo_Util::fecha( $inv->usada, true ) ) . '.</p>';
			} elseif ( $inv->anulada ) {
				echo '<p>Última invitación anulada.</p>';
			} elseif ( strtotime( $inv->vence ) < strtotime( Inmo_Util::ahora() ) ) {
				echo '<p>La invitación venció el ' . esc_html( Inmo_Util::fecha( $inv->vence, true ) ) . '.</p>';
			} else {
				echo '<p>Invitación vigente hasta el ' . esc_html( Inmo_Util::fecha( $inv->vence, true ) ) . '.</p>';
			}
		}
		?>
		<p><label><input type="checkbox" name="inmo_invitar" value="1"> Generar un nuevo enlace para que el titular complete características y fotos (anula el anterior; se envía por correo y se muestra para copiarlo)</label></p>
		<?php
	}

	public static function mb_expediente( $post ) {
		$ops = Inmo_Oportunidades::de_propiedad( $post->ID );
		echo '<h3 class="inmo-subtitulo">Oportunidades</h3>';
		if ( $ops ) {
			echo '<table class="widefat striped"><thead><tr><th>Cliente</th><th>Etapa</th><th>Valor</th><th>Actualizada</th></tr></thead><tbody>';
			foreach ( $ops as $o ) {
				echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=inmo-oportunidades&id=' . $o->id ) ) . '">' . esc_html( $o->nombre ) . '</a></td><td>' . esc_html( Inmo_Oportunidades::ETAPAS[ $o->etapa ] ) . '</td><td>' . esc_html( Inmo_Oportunidades::valor_texto( $o ) ) . '</td><td>' . esc_html( Inmo_Util::fecha( $o->actualizado ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Sin oportunidades. Se crean al recibir consultas o desde la ficha de un contacto.</p>';
		}
		$ofs = Inmo_Ofertas::de_propiedad( $post->ID );
		if ( $ofs ) {
			echo '<h3 class="inmo-subtitulo">Ofertas</h3><table class="widefat striped"><tbody>';
			foreach ( $ofs as $f ) {
				echo '<tr><td>' . esc_html( Inmo_Util::fecha( $f->creado ) ) . '</td><td>' . esc_html( $f->nombre ) . '</td><td><strong>' . esc_html( Inmo_Util::formatear_monto( $f->monto, $f->moneda ) ) . '</strong></td><td>' . esc_html( Inmo_Ofertas::ESTADOS[ $f->estado ] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( 'arriendo' === Inmo_Propiedades::termino( $post->ID, Inmo_Propiedades::TAX_OPERACION ) ) {
			$adm = Inmo_Arriendos::vigente_de_propiedad( $post->ID );
			echo '<h3 class="inmo-subtitulo">Administración del arriendo</h3>';
			if ( $adm ) {
				$arr = Inmo_Contactos::get( $adm->arrendatario_id );
				echo '<p>Vigente desde el ' . esc_html( Inmo_Util::fecha( $adm->fecha_inicio ) ) . ' · ' . esc_html( $arr ? $arr->nombre : '' ) . ' · renta ' . esc_html( Inmo_Util::formatear_monto( $adm->renta, $adm->moneda ) ) . ' · <a href="' . esc_url( Inmo_Admin_Administracion::url( $adm->id ) ) . '">Cobros y liquidaciones</a></p>';
			} else {
				echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=inmo-arriendos&nuevo=1&propiedad=' . $post->ID ) ) . '">Iniciar administración</a> <span class="description">También desde la oportunidad ganada (trae renta, partes y contrato).</span></p>';
			}
		}
		echo '<h3 class="inmo-subtitulo">Documentos emitidos</h3>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=inmo-documento&propiedad=' . $post->ID ) ) . '">Emitir mandato u otro documento de la propiedad</a> <span class="description">Contratos de arriendo, promesas, reservas y órdenes de visita se emiten desde la oportunidad.</span></p>';
		Inmo_Admin_Oportunidades::tabla_emisiones( Inmo_Contratos::de_propiedad( $post->ID ) );

		$op = Inmo_Propiedades::termino( $post->ID, Inmo_Propiedades::TAX_OPERACION );
		echo '<h3 class="inmo-subtitulo">Antecedentes' . ( 'venta' === $op ? ' para la compraventa' : ' para el arriendo' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>Antecedente</th><th>Estado</th><th>Subir / reemplazar</th></tr></thead><tbody>';
		foreach ( Inmo_Expediente::estado_antecedentes( $post->ID ) as $k => $a ) {
			echo '<tr><td>' . esc_html( $a['label'] ) . '</td><td>';
			echo $a['doc'] ? '✔ <a href="' . esc_url( Inmo_Documentos::url( $a['doc']->id ) ) . '">' . esc_html( $a['doc']->nombre ) . '</a> <span class="description">' . esc_html( Inmo_Util::fecha( $a['doc']->creado ) ) . '</span>' : '<span class="inmo-badge inmo-badge--pendiente">Pendiente</span>';
			echo '</td><td><label class="screen-reader-text" for="ant-' . esc_attr( $k ) . '">' . esc_html( $a['label'] ) . '</label><input type="file" id="ant-' . esc_attr( $k ) . '" name="inmo_ant_' . esc_attr( $k ) . '" accept=".pdf,.jpg,.jpeg,.png"></td></tr>';
		}
		echo '</tbody></table>';
		if ( 'venta' === $op ) {
			$esc = Inmo_Expediente::escritura( $post->ID );
			echo '<h3 class="inmo-subtitulo">Escritura de compraventa definitiva</h3>';
			echo $esc ? '<p>Archivada: <a href="' . esc_url( Inmo_Documentos::url( $esc->id ) ) . '">' . esc_html( $esc->nombre ) . '</a> (' . esc_html( Inmo_Util::fecha( $esc->creado ) ) . ')</p>' : '<p>Aún no se archiva la escritura.</p>';
			echo '<p><label for="inmo_escritura">Archivar escritura (PDF)</label> <input type="file" id="inmo_escritura" name="inmo_escritura" accept=".pdf"></p>';
		}
		echo '<p class="description">Los archivos se guardan en el almacenamiento privado al pulsar «Guardar ficha».</p>';
	}

	public static function mb_actividad( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>Sin actividad.</p>';
			return;
		}
		$consultas = Inmo_Consultas::de_propiedad( $post->ID );
		$visibles  = array_filter( $consultas, array( 'Inmo_Permisos', 'puede_ver_consulta' ) );
		if ( $visibles ) {
			echo '<table class="widefat striped inmo-actividad"><thead><tr><th>Fecha</th><th>Tipo</th><th>Contacto</th><th>Detalle</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
			foreach ( $visibles as $q ) {
				$q->datos = $q->datos ? json_decode( $q->datos, true ) : array();
				echo '<tr><td>' . esc_html( Inmo_Util::fecha( $q->creado, true ) ) . '</td>';
				echo '<td>' . esc_html( Inmo_Consultas::TIPOS[ $q->tipo ] ) . '</td>';
				echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $q->contacto_id ) ) . '">' . esc_html( $q->nombre ) . '</a><br><span class="description">' . esc_html( $q->telefono ) . '</span></td>';
				echo '<td>' . esc_html( wp_trim_words( $q->mensaje, 18 ) );
				if ( 'visita' === $q->tipo ) {
					echo '<br><span class="description">Prefiere: ' . esc_html( Inmo_Util::fecha( $q->datos['fecha_preferida'] ?? '' ) . ' · ' . ( Inmo_Consultas::FRANJAS[ $q->datos['franja'] ?? '' ] ?? '' ) ) . '</span>';
					if ( $q->visita_fecha ) {
						echo '<br><strong>Cita: ' . esc_html( Inmo_Util::fecha( $q->visita_fecha, true ) ) . '</strong>';
					}
				}
				echo '</td><td><span class="inmo-badge inmo-badge--' . esc_attr( $q->estado ) . '">' . esc_html( Inmo_Consultas::etiqueta_estado( $q ) ) . '</span></td><td>';
				Inmo_Admin_Consultas::controles_inline( $q, 'inmo_q' );
				echo '</td></tr>';
			}
			echo '</tbody></table><p class="description">Las acciones se aplican al pulsar «Aplicar» (también guarda la ficha).</p>';
		} else {
			echo '<p>Aún no hay consultas ni visitas.</p>';
		}
		$hist = Inmo_Historial::de( 'propiedad_id', $post->ID, 30 );
		if ( $hist ) {
			echo '<h4>Historial</h4><ol class="inmo-historial">';
			foreach ( $hist as $h ) {
				$u = $h->user_id ? get_the_author_meta( 'display_name', $h->user_id ) : 'Sitio web';
				echo '<li><time>' . esc_html( Inmo_Util::fecha( $h->creado, true ) ) . '</time> <strong>' . esc_html( Inmo_Historial::etiqueta( $h->accion ) ) . '</strong> — ' . esc_html( $h->detalle ) . ' <span class="description">(' . esc_html( $u ) . ')</span></li>';
			}
			echo '</ol>';
		}
	}

	/* =====================================================================
	 * Guardado
	 * =================================================================== */

	/** Autor (corredor responsable) y título sugerido antes de insertar. */
	public static function datos_post( $data, $postarr ) {
		if ( INMO_CPT !== $data['post_type'] || empty( $_POST['inmo_nonce'] ) || empty( $postarr['ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $data;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['inmo_nonce'] ) ), 'inmo_guardar_' . (int) $postarr['ID'] ) ) {
			return $data;
		}
		if ( Inmo_Permisos::ve_todo() && ! empty( $_POST['inmo_corredor'] ) ) {
			$uid = (int) $_POST['inmo_corredor'];
			$u   = get_userdata( $uid );
			if ( $u && ( user_can( $u, 'edit_inmo_propiedades' ) ) ) {
				$data['post_author'] = $uid;
			}
		}
		if ( '' === trim( (string) $data['post_title'] ) ) {
			$tipo   = Inmo_Util::post( 'inmo_tipo' );
			$op     = Inmo_Util::post( 'inmo_operacion' );
			$comuna = get_term( (int) Inmo_Util::post( 'inmo_comuna' ), Inmo_Propiedades::TAX_UBICACION );
			$t      = isset( Inmo_Esquema::TIPOS[ $tipo ] ) ? Inmo_Esquema::TIPOS[ $tipo ] : 'Propiedad';
			$t     .= isset( Inmo_Esquema::OPERACIONES[ $op ] ) ? ' en ' . strtolower( Inmo_Esquema::OPERACIONES[ $op ] ) : '';
			$t     .= ( $comuna && ! is_wp_error( $comuna ) ) ? ' en ' . $comuna->name : '';
			$data['post_title'] = wp_slash( $t );
		}
		return $data;
	}

	public static function guardar( $post_id, $post ) {
		if ( self::$guardando || defined( 'DOING_AUTOSAVE' ) || wp_is_post_revision( $post_id ) || empty( $_POST['inmo_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['inmo_nonce'] ) ), 'inmo_guardar_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		self::$guardando = true;
		Inmo_Propiedades::asegurar_codigo( $post_id );

		// Datos públicos.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanea campo a campo en guardar_publicos().
		$c   = isset( $_POST['inmo_c'] ) && is_array( $_POST['inmo_c'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['inmo_c'] ) ) : array();
		$pub = array_merge(
			$c,
			array(
				'tipo'        => Inmo_Util::post( 'inmo_tipo' ),
				'operacion'   => Inmo_Util::post( 'inmo_operacion' ),
				'precio'      => Inmo_Util::post( 'inmo_precio' ),
				'moneda'      => Inmo_Util::post( 'inmo_moneda' ),
				'sector'      => Inmo_Util::post( 'inmo_sector' ),
				'precision'   => Inmo_Util::post( 'inmo_precision' ),
				'destacado'   => ! empty( $_POST['inmo_destacado'] ),
				'visibilidad' => Inmo_Util::post( 'inmo_visibilidad' ),
			)
		);
		// Todos los campos del esquema se envían (los no aplicables se borran).
		foreach ( Inmo_Esquema::campos() as $k => $def ) {
			if ( ! array_key_exists( $k, $pub ) ) {
				$pub[ $k ] = '';
			}
		}
		if ( ! empty( $_POST['inmo_equip_enviado'] ) ) {
			$pub['equipamiento'] = isset( $_POST['inmo_equip'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['inmo_equip'] ) ) : array();
		}
		$comuna = (int) Inmo_Util::post( 'inmo_comuna' );
		if ( $comuna ) {
			$pub['comuna_id'] = $comuna;
		} else {
			wp_set_object_terms( $post_id, array(), Inmo_Propiedades::TAX_UBICACION );
		}

		$puede_privado = Inmo_Permisos::puede_ver_privado( $post_id );
		if ( $puede_privado && isset( $_POST['inmo_p'] ) && is_array( $_POST['inmo_p'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se sanea en Inmo_Privado::guardar().
			Inmo_Privado::guardar( $post_id, wp_unslash( $_POST['inmo_p'] ) );
		}
		Inmo_Propiedades::guardar_publicos( $post_id, $pub );

		// Galería.
		if ( isset( $_POST['inmo_galeria'] ) ) {
			$ids = array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['inmo_galeria'] ) ) ) ) );
			$ids = array_filter( $ids, static function ( $id ) { return 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ); } );
			Inmo_Propiedades::guardar_galeria( $post_id, $ids );
		}

		if ( $puede_privado ) {
			self::guardar_privado( $post_id );
		}
		self::acciones_consultas();

		// Estado comercial (al final: depende de fotos, comuna y precio ya guardados).
		$pedido = Inmo_Util::post( 'inmo_estado' );
		if ( ! empty( $_POST['inmo_aprobar'] ) ) {
			$pedido = 'disponible';
		} elseif ( ! empty( $_POST['inmo_enviar_revision'] ) ) {
			$pedido = 'en_revision';
		}
		if ( $pedido ) {
			$anterior = get_post_meta( $post_id, '_inmo_estado', true );
			$r        = Inmo_Propiedades::cambiar_estado( $post_id, $pedido, Inmo_Util::post( 'inmo_visibilidad' ) );
			if ( is_wp_error( $r ) ) {
				// No se pudo publicar: se conserva el estado anterior (o en revisión si venía de borrador).
				update_post_meta( $post_id, '_inmo_estado', $anterior ? $anterior : 'borrador' );
				Inmo_Propiedades::sincronizar_post_status( $post_id );
				Inmo_Admin::avisar( 'error', '<strong>No se publicó.</strong> ' . esc_html( $r->get_error_message() ) );
			} elseif ( $r !== $pedido ) {
				Inmo_Admin::avisar( 'warning', 'La propiedad quedó <strong>' . esc_html( Inmo_Esquema::ESTADOS[ $r ] ) . '</strong>: la publicación requiere aprobación de administración.' );
			} elseif ( in_array( $r, Inmo_Esquema::ESTADOS_PUBLICABLES, true ) && $anterior !== $r ) {
				Inmo_Admin::avisar( 'success', 'Propiedad <strong>' . esc_html( Inmo_Esquema::ESTADOS[ $r ] ) . '</strong>' . ( 'publish' === get_post_status( $post_id ) ? ' y publicada en el sitio. <a href="' . esc_url( get_permalink( $post_id ) ) . '">Ver ficha pública</a>' : ' (oculta en el sitio).' ) );
			}
		}
		self::$guardando = false;
	}

	private static function guardar_privado( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificado en guardar().
		foreach ( isset( $_POST['inmo_quitar_titular'] ) ? array_map( 'intval', (array) $_POST['inmo_quitar_titular'] ) : array() as $cid ) {
			Inmo_Contactos::desvincular_titular( $post_id, $cid );
		}
		$existente = (int) Inmo_Util::post( 'inmo_titular_existente' );
		if ( $existente && Inmo_Permisos::puede_ver_contacto( $existente ) ) {
			Inmo_Contactos::vincular_titular( $post_id, $existente );
		}
		$nt = isset( $_POST['inmo_nt'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['inmo_nt'] ) ) : array();
		if ( ! empty( $nt['nombre'] ) ) {
			if ( ! empty( $nt['rut'] ) && ! Inmo_Util::normalizar_rut( $nt['rut'] ) ) {
				Inmo_Admin::avisar( 'error', 'El RUT del nuevo titular no es válido; se guardó sin RUT.' );
			}
			$cid = Inmo_Contactos::upsert( array_merge( $nt, array( 'roles' => array( 'propietario' ), 'origen' => 'manual', 'corredor_id' => (int) get_post_field( 'post_author', $post_id ) ) ) );
			if ( $cid ) {
				Inmo_Contactos::vincular_titular( $post_id, $cid );
			}
		}

		// Documento.
		if ( ! empty( $_FILES['inmo_doc']['name'] ) ) {
			$r = Inmo_Documentos::guardar_subida( $_FILES['inmo_doc'], $post_id, Inmo_Util::post( 'inmo_doc_tipo', 'otro' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $r ) ) {
				Inmo_Admin::avisar( 'error', 'Documento: ' . esc_html( $r->get_error_message() ) );
			}
		}

		// Antecedentes y escritura (expediente).
		$op_prop = Inmo_Propiedades::termino( $post_id, Inmo_Propiedades::TAX_OPERACION );
		foreach ( Inmo_Expediente::antecedentes( $op_prop ) as $k => $l ) {
			if ( ! empty( $_FILES[ 'inmo_ant_' . $k ]['name'] ) ) {
				$r = Inmo_Documentos::guardar_subida( $_FILES[ 'inmo_ant_' . $k ], $post_id, 'antecedente', 0, false, $k ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( is_wp_error( $r ) ) {
					Inmo_Admin::avisar( 'error', esc_html( $l . ': ' . $r->get_error_message() ) );
				}
			}
		}
		if ( ! empty( $_FILES['inmo_escritura']['name'] ) ) {
			$r = Inmo_Documentos::guardar_subida( $_FILES['inmo_escritura'], $post_id, 'escritura' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			Inmo_Admin::avisar( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? esc_html( 'Escritura: ' . $r->get_error_message() ) : 'Escritura archivada en el expediente.' );
		}

		// Fotos del propietario.
		foreach ( isset( $_POST['inmo_autorizar_foto'] ) ? array_map( 'intval', (array) $_POST['inmo_autorizar_foto'] ) : array() as $doc ) {
			$r = Inmo_Documentos::autorizar_foto( $doc, $post_id );
			if ( is_wp_error( $r ) ) {
				Inmo_Admin::avisar( 'error', 'Foto: ' . esc_html( $r->get_error_message() ) );
			}
		}
		foreach ( isset( $_POST['inmo_descartar_foto'] ) ? array_map( 'intval', (array) $_POST['inmo_descartar_foto'] ) : array() as $doc ) {
			$d = Inmo_Documentos::get( $doc );
			if ( $d && (int) $d->propiedad_id === (int) $post_id ) {
				Inmo_Documentos::marcar( $doc, 'descartada' );
			}
		}

		// Invitación.
		if ( ! empty( $_POST['inmo_invitar'] ) ) {
			$tit = Inmo_Contactos::titulares( $post_id );
			if ( ! $tit ) {
				Inmo_Admin::avisar( 'error', 'Para invitar, primero agrega un titular.' );
			} elseif ( ! in_array( get_post_meta( $post_id, '_inmo_estado', true ), array( 'borrador', 'en_revision' ), true ) ) {
				Inmo_Admin::avisar( 'error', 'Solo se puede invitar al propietario mientras la ficha está en borrador o en revisión.' );
			} else {
				$inv = Inmo_Invitaciones::crear( $post_id, $tit[0]->id );
				$ok  = Inmo_Correo::invitacion( $post_id, $tit[0]->id, $inv['url'], $inv['vence'] );
				Inmo_Admin::avisar(
					$ok ? 'success' : 'warning',
					'Enlace para ' . esc_html( $tit[0]->nombre ) . ' (vence el ' . esc_html( Inmo_Util::fecha( $inv['vence'] ) ) . ')' . ( $ok ? ', enviado por correo' : '. <strong>El correo no se pudo enviar</strong>: cópialo y envíalo por WhatsApp' ) . ':<br><input type="text" readonly class="large-text" value="' . esc_url( $inv['url'] ) . '" aria-label="Enlace de invitación"> <button type="button" class="button" data-inmo-copiar>Copiar</button><br>Este enlace no se volverá a mostrar.'
				);
			}
		}
		// phpcs:enable
	}

	/** Confirmar / reprogramar / cancelar visitas y cambiar estado de consultas desde la ficha. */
	public static function acciones_consultas( $prefijo = 'inmo_q' ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificado por quien llama.
		if ( empty( $_POST[ $prefijo . '_aplicar' ] ) ) {
			return;
		}
		$id     = (int) $_POST[ $prefijo . '_aplicar' ];
		$estado = isset( $_POST[ $prefijo ][ $id ]['estado'] ) ? sanitize_key( wp_unslash( $_POST[ $prefijo ][ $id ]['estado'] ) ) : '';
		$fecha  = isset( $_POST[ $prefijo ][ $id ]['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefijo ][ $id ]['fecha'] ) ) : '';
		$nota   = isset( $_POST[ $prefijo ][ $id ]['nota'] ) ? sanitize_text_field( wp_unslash( $_POST[ $prefijo ][ $id ]['nota'] ) ) : '';
		// phpcs:enable
		$q = Inmo_Consultas::get( $id );
		if ( ! $q || ! Inmo_Permisos::puede_ver_consulta( $q ) || ! $estado ) {
			return;
		}
		$r = Inmo_Consultas::cambiar_estado( $id, $estado, $fecha, $nota );
		if ( is_wp_error( $r ) ) {
			Inmo_Admin::avisar( 'error', esc_html( $r->get_error_message() ) );
		} else {
			Inmo_Admin::avisar( 'success', esc_html( Inmo_Consultas::TIPOS[ $q->tipo ] . ' de ' . ( Inmo_Contactos::get( $q->contacto_id )->nombre ?? '' ) . ': ' . Inmo_Consultas::estados_de( $q->tipo )[ $estado ] ) . '.' );
		}
	}

	/* =====================================================================
	 * Listado
	 * =================================================================== */

	public static function columnas( $cols ) {
		return array(
			'cb'           => $cols['cb'],
			'inmo_foto'    => '<span class="screen-reader-text">Foto</span>',
			'inmo_codigo'  => 'Código',
			'title'        => 'Propiedad',
			'inmo_op'      => 'Operación',
			'inmo_comuna'  => 'Comuna',
			'inmo_precio'  => 'Precio',
			'inmo_estado'  => 'Estado',
			'inmo_corredor' => 'Corredor',
			'inmo_act'     => 'Consultas',
		);
	}

	public static function columna( $col, $post_id ) {
		switch ( $col ) {
			case 'inmo_foto':
				$g = Inmo_Propiedades::galeria( $post_id );
				echo $g ? wp_get_attachment_image( $g[0], array( 60, 60 ) ) : '—';
				break;
			case 'inmo_codigo':
				echo '<strong>' . esc_html( get_post_meta( $post_id, '_inmo_codigo', true ) ) . '</strong>';
				break;
			case 'inmo_op':
				$op = Inmo_Propiedades::termino( $post_id, Inmo_Propiedades::TAX_OPERACION );
				$t  = Inmo_Propiedades::termino( $post_id, Inmo_Propiedades::TAX_TIPO );
				echo esc_html( ( Inmo_Esquema::OPERACIONES[ $op ] ?? '—' ) . ' · ' . ( Inmo_Esquema::TIPOS[ $t ] ?? '—' ) );
				break;
			case 'inmo_comuna':
				$c = Inmo_Propiedades::comuna( $post_id );
				echo esc_html( $c ? $c->name : '—' );
				break;
			case 'inmo_precio':
				echo esc_html( Inmo_Util::formatear_monto( get_post_meta( $post_id, '_inmo_precio', true ), get_post_meta( $post_id, '_inmo_moneda', true ) ) );
				break;
			case 'inmo_estado':
				$e = get_post_meta( $post_id, '_inmo_estado', true );
				echo '<span class="inmo-badge inmo-badge--' . esc_attr( $e ) . '">' . esc_html( Inmo_Esquema::ESTADOS[ $e ] ?? '—' ) . '</span>';
				if ( 'oculta' === get_post_meta( $post_id, '_inmo_visibilidad', true ) ) {
					echo ' <span class="description">(oculta)</span>';
				}
				break;
			case 'inmo_corredor':
				echo esc_html( get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) ) );
				break;
			case 'inmo_act':
				$q = Inmo_Consultas::de_propiedad( $post_id );
				$p = count( array_filter( $q, static function ( $x ) { return 'visita' === $x->tipo && 'pendiente' === $x->estado; } ) );
				echo (int) count( $q ) . ( $p ? ' · <strong>' . (int) $p . ' visita(s) por confirmar</strong>' : '' );
				break;
		}
	}

	public static function listado_query( WP_Query $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || INMO_CPT !== $q->get( 'post_type' ) ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		if ( ! Inmo_Permisos::ve_todo() ) {
			$q->set( 'author', get_current_user_id() );
		}
		$estado = Inmo_Util::get( 'inmo_estado' );
		if ( $estado && isset( Inmo_Esquema::ESTADOS[ $estado ] ) ) {
			$q->set( 'meta_query', array( array( 'key' => '_inmo_estado', 'value' => $estado ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$codigo = Inmo_Util::get( 's' );
		if ( $codigo && preg_match( '/^[A-Za-z]+-\d+$/', $codigo ) ) {
			$q->set( 's', '' );
			$q->set( 'meta_query', array( array( 'key' => '_inmo_codigo', 'value' => strtoupper( $codigo ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
	}

	public static function filtros_listado( $post_type ) {
		if ( INMO_CPT !== $post_type ) {
			return;
		}
		$actual = Inmo_Util::get( 'inmo_estado' );
		echo '<label class="screen-reader-text" for="inmo_estado_filtro">Estado comercial</label><select name="inmo_estado" id="inmo_estado_filtro"><option value="">Todos los estados</option>';
		foreach ( Inmo_Esquema::ESTADOS as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $actual, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
	}

	public static function acciones_fila( $acciones, $post ) {
		if ( INMO_CPT === $post->post_type ) {
			unset( $acciones['inline hide-if-no-js'] );
			if ( isset( $acciones['edit'] ) ) {
				$acciones['edit'] = str_replace( '>Edit<', '>Abrir ficha<', $acciones['edit'] );
			}
		}
		return $acciones;
	}

	/** Las vistas por estado de WordPress cuentan publicaciones ajenas; para corredores se ocultan. */
	public static function vistas( $vistas ) {
		if ( ! Inmo_Permisos::ve_todo() ) {
			return array();
		}
		return $vistas;
	}

	public static function acciones_masivas( $acciones ) {
		unset( $acciones['edit'] );
		return $acciones;
	}
}
