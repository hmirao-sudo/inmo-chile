<?php
/**
 * Ajustes: marca, contacto, UF, códigos, invitaciones y datos de prueba.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Admin_Ajustes {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_inmo_ajustes', array( __CLASS__, 'guardar' ) );
		add_action( 'admin_post_inmo_datos_prueba', array( __CLASS__, 'datos_prueba' ) );
		add_action( 'admin_post_inmo_actualizar_uf', array( __CLASS__, 'actualizar_uf' ) );
	}

	public static function menu() {
		add_submenu_page( 'inmo', 'Ajustes', 'Ajustes', 'inmo_ajustes', 'inmo-ajustes', array( __CLASS__, 'pagina' ) );
	}

	private static function campos() {
		return array(
			'Marca (se puede cambiar en cualquier momento)' => array(
				'marca_nombre'   => array( 'Nombre de la corredora', 'text' ),
				'marca_eslogan'  => array( 'Eslogan', 'text' ),
				'color_primario' => array( 'Color primario', 'color' ),
				'color_acento'   => array( 'Color de acento', 'color' ),
				'logo_id'        => array( 'Logo (ID de imagen de la biblioteca; 0 = solo texto)', 'number' ),
			),
			'Contacto público' => array(
				'telefono'          => array( 'Teléfono', 'text' ),
				'whatsapp'          => array( 'WhatsApp', 'text' ),
				'email'             => array( 'Correo público', 'email' ),
				'direccion_oficina' => array( 'Dirección de la oficina', 'text' ),
				'horario'           => array( 'Horario de atención', 'text' ),
				'respuesta_plazo'   => array( 'Plazo de respuesta prometido', 'text' ),
			),
			'Valor UF (equivalencias y filtros por precio)' => array(
				'uf_valor'  => array( 'Valor UF en pesos', 'text' ),
				'uf_fecha'  => array( 'Fecha del valor', 'date' ),
				'uf_fuente' => array( 'Fuente (se muestra en el sitio)', 'text' ),
				'uf_fuente_auto' => array( 'Actualización automática diaria', 'fuente_uf' ),
				'cmf_api_key'    => array( 'Clave API CMF (solo si eliges la CMF)', 'text' ),
			),
			'Administración de arriendos' => array(
				'admin_comision_pct'   => array( 'Comisión de administración por defecto (%)', 'text' ),
				'iva_pct'              => array( 'IVA sobre la comisión (%)', 'text' ),
				'cobro_dia_generacion' => array( 'Día del mes en que se generan los cobros', 'number' ),
				'datos_pago'           => array( 'Datos para el pago (se envían al arrendatario)', 'text' ),
			),
			'Operación' => array(
				'codigo_prefijo'       => array( 'Prefijo de códigos (solo letras; no cambia códigos existentes)', 'text' ),
				'precision_defecto'    => array( 'Precisión de ubicación por defecto', 'precision' ),
				'invitacion_dias'      => array( 'Días de vigencia de la invitación al propietario', 'number' ),
				'corredor_defecto'     => array( 'Corredor que recibe captaciones y consultas generales', 'corredor' ),
				'email_notificaciones' => array( 'Correo para avisos internos (vacío = correo del administrador)', 'email' ),
				'resultados_pagina'    => array( 'Resultados por página', 'number' ),
			),
		);
	}

	public static function pagina() {
		if ( ! current_user_can( 'inmo_ajustes' ) ) {
			wp_die( 'Sin permiso.' );
		}
		$v = Inmo_Ajustes::todos();
		?>
		<div class="wrap inmo-wrap">
			<h1>Ajustes de la corredora</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="inmo_ajustes">
				<?php wp_nonce_field( 'inmo_ajustes' ); ?>
				<?php foreach ( self::campos() as $seccion => $campos ) : ?>
					<h2><?php echo esc_html( $seccion ); ?></h2>
					<table class="form-table" role="presentation"><tbody>
					<?php foreach ( $campos as $k => $c ) : ?>
						<tr><th scope="row"><label for="aj_<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $c[0] ); ?></label></th><td>
						<?php if ( 'precision' === $c[1] ) : ?>
							<select id="aj_<?php echo esc_attr( $k ); ?>" name="aj[<?php echo esc_attr( $k ); ?>]">
							<?php foreach ( Inmo_Esquema::PRECISION as $pk => $pl ) : ?><option value="<?php echo esc_attr( $pk ); ?>" <?php selected( $v[ $k ], $pk ); ?>><?php echo esc_html( $pl ); ?></option><?php endforeach; ?>
							</select>
						<?php elseif ( 'fuente_uf' === $c[1] ) : ?>
							<select id="aj_<?php echo esc_attr( $k ); ?>" name="aj[<?php echo esc_attr( $k ); ?>]">
							<?php foreach ( Inmo_Indicadores::FUENTES as $fk => $fl ) : ?><option value="<?php echo esc_attr( $fk ); ?>" <?php selected( $v[ $k ], $fk ); ?>><?php echo esc_html( $fl ); ?></option><?php endforeach; ?>
							</select>
						<?php elseif ( 'corredor' === $c[1] ) : ?>
							<select id="aj_<?php echo esc_attr( $k ); ?>" name="aj[<?php echo esc_attr( $k ); ?>]"><option value="0">— Primer administrador —</option>
							<?php foreach ( Inmo_Permisos::corredores() as $u ) : ?><option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $v[ $k ], (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option><?php endforeach; ?>
							</select>
						<?php else : ?>
							<input class="regular-text" type="<?php echo esc_attr( $c[1] ); ?>" id="aj_<?php echo esc_attr( $k ); ?>" name="aj[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $v[ $k ] ); ?>">
						<?php endif; ?>
						</td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endforeach; ?>
				<p class="description">Al cambiar el valor UF se recalculan los precios de referencia usados para filtrar. Los precios publicados se mantienen en su moneda original.</p>
				<?php submit_button( 'Guardar ajustes' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="inmo_actualizar_uf"><?php wp_nonce_field( 'inmo_actualizar_uf' ); ?>
				<p><button class="button">Actualizar la UF ahora desde la fuente automática</button></p>
			</form>

			<h2 id="datos-prueba">Datos de prueba</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="inmo_datos_prueba">
				<?php wp_nonce_field( 'inmo_datos_prueba' ); ?>
				<?php if ( Inmo_Datos_Prueba::cargados() ) : ?>
					<p>Los datos de prueba están cargados (2 inmuebles, 1 propietaria y 2 corredores ficticios).</p>
					<button class="button" name="op" value="eliminar" onclick="return confirm('¿Eliminar los datos de prueba?');">Eliminar datos de prueba</button>
				<?php else : ?>
					<p>Crea una casa en venta (La Reina, UF 5.200), un departamento en arriendo (Providencia, $650.000), una propietaria y dos corredores ficticios. Todo queda marcado y se puede eliminar.</p>
					<button class="button button-primary" name="op" value="cargar">Cargar datos de prueba</button>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	public static function guardar() {
		check_admin_referer( 'inmo_ajustes' );
		if ( ! current_user_can( 'inmo_ajustes' ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		$in    = isset( $_POST['aj'] ) ? (array) wp_unslash( $_POST['aj'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nuevo = array();
		foreach ( self::campos() as $campos ) {
			foreach ( $campos as $k => $c ) {
				if ( ! isset( $in[ $k ] ) ) {
					continue;
				}
				$val = $in[ $k ];
				switch ( $c[1] ) {
					case 'color':
						$val = sanitize_hex_color( $val ) ? sanitize_hex_color( $val ) : Inmo_Ajustes::defaults()[ $k ];
						break;
					case 'email':
						$val = sanitize_email( $val );
						break;
					case 'fuente_uf':
						$val = isset( Inmo_Indicadores::FUENTES[ $val ] ) ? $val : 'manual';
						break;
					case 'number':
					case 'corredor':
						$val = absint( $val );
						break;
					default:
						$val = sanitize_text_field( $val );
				}
				$nuevo[ $k ] = $val;
			}
		}
		if ( isset( $nuevo['uf_valor'] ) ) {
			$uf               = Inmo_Util::parse_numero( $nuevo['uf_valor'] );
			$nuevo['uf_valor'] = ( '' !== $uf && $uf > 1000 ) ? number_format( $uf, 2, '.', '' ) : Inmo_Ajustes::get( 'uf_valor' );
		}
		if ( isset( $nuevo['codigo_prefijo'] ) ) {
			$nuevo['codigo_prefijo'] = strtoupper( preg_replace( '/[^A-Za-z]/', '', $nuevo['codigo_prefijo'] ) );
		}
		$uf_antes = Inmo_Ajustes::get( 'uf_valor' ) . '|' . Inmo_Ajustes::get( 'uf_fecha' );
		Inmo_Ajustes::guardar( $nuevo );
		if ( isset( $nuevo['uf_valor'], $nuevo['uf_fecha'] ) && $uf_antes !== $nuevo['uf_valor'] . '|' . $nuevo['uf_fecha'] && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $nuevo['uf_fecha'] ) ) {
			$h                       = Inmo_Indicadores::historial();
			$h[ $nuevo['uf_fecha'] ] = (float) $nuevo['uf_valor'];
			update_option( Inmo_Indicadores::OPCION_HIST, $h, false );
		}
		Inmo_Admin::avisar( 'success', 'Ajustes guardados.' );
		wp_safe_redirect( admin_url( 'admin.php?page=inmo-ajustes' ) );
		exit;
	}

	public static function actualizar_uf() {
		check_admin_referer( 'inmo_actualizar_uf' );
		if ( ! current_user_can( 'inmo_ajustes' ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		$r = Inmo_Indicadores::actualizar_uf();
		Inmo_Admin::avisar( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? esc_html( $r->get_error_message() ) : 'UF actualizada: ' . esc_html( Inmo_Util::formatear_monto( Inmo_Ajustes::get( 'uf_valor' ), 'CLP' ) . ' al ' . Inmo_Util::fecha( Inmo_Ajustes::get( 'uf_fecha' ) ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=inmo-ajustes' ) );
		exit;
	}

	public static function datos_prueba() {
		check_admin_referer( 'inmo_datos_prueba' );
		if ( ! current_user_can( 'inmo_ajustes' ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		if ( 'eliminar' === Inmo_Util::post( 'op' ) ) {
			Inmo_Datos_Prueba::eliminar();
			Inmo_Admin::avisar( 'success', 'Datos de prueba eliminados.' );
		} else {
			$r = Inmo_Datos_Prueba::cargar();
			Inmo_Admin::avisar( 'success', 'Datos de prueba cargados: ' . count( $r['propiedades'] ) . ' propiedades. Usuarios corredores: <code>camila.rojas</code> y <code>diego.munoz</code> (contraseña <code>Prueba.Corredor.2026</code>).' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=inmo-ajustes' ) );
		exit;
	}
}
