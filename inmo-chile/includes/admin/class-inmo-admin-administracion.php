<?php
/**
 * Admin: arriendos administrados, cobranza mensual y liquidaciones a propietarios.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Admin_Administracion {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 13 );
		add_action( 'admin_post_inmo_admin_arriendo', array( __CLASS__, 'procesar' ) );
	}

	public static function menu() {
		$h = add_submenu_page( 'inmo', 'Arriendos administrados', 'Arriendos administrados', 'inmo_gestionar', 'inmo-arriendos', array( __CLASS__, 'pagina_arriendos' ) );
		add_submenu_page( 'inmo', 'Cobranza', 'Cobranza', 'inmo_gestionar', 'inmo-cobranza', array( __CLASS__, 'pagina_cobranza' ) );
		add_submenu_page( 'inmo', 'Liquidaciones', 'Liquidaciones', 'inmo_gestionar', 'inmo-liquidaciones', array( __CLASS__, 'pagina_liquidaciones' ) );
		add_action( 'load-' . $h, array( __CLASS__, 'verificar' ) );
	}

	public static function verificar() {
		$id = absint( Inmo_Util::get( 'id' ) );
		if ( $id && ! Inmo_Arriendos::puede_ver( Inmo_Arriendos::get( $id ) ) ) {
			wp_die( 'No tienes acceso a este arriendo.', 'Acceso denegado', array( 'response' => 403, 'back_link' => true ) );
		}
		$pid = absint( Inmo_Util::get( 'propiedad' ) );
		if ( $pid && ! Inmo_Permisos::puede_ver_privado( $pid ) ) {
			wp_die( 'Sin permiso.', 'Acceso denegado', array( 'response' => 403 ) );
		}
	}

	public static function url( $id, array $extra = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'inmo-arriendos', 'id' => (int) $id ), $extra ), admin_url( 'admin.php' ) );
	}

	private static function abrir( $accion, array $ocultos = array(), $multipart = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="inmo_admin_arriendo"><input type="hidden" name="accion" value="' . esc_attr( $accion ) . '">';
		foreach ( $ocultos as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<input type="hidden" name="_volver" value="' . esc_url( remove_query_arg( array( 'liq_previa' ) ) ) . '">';
		wp_nonce_field( 'inmo_admin_arriendo' );
	}

	private static function badge( $estado, $texto ) {
		return '<span class="inmo-badge inmo-badge--' . esc_attr( $estado ) . '">' . esc_html( $texto ) . '</span>';
	}

	/* =====================================================================
	 * Arriendos
	 * =================================================================== */

	public static function pagina_arriendos() {
		if ( Inmo_Util::get( 'nuevo' ) ) {
			self::form_nuevo();
			return;
		}
		$id = absint( Inmo_Util::get( 'id' ) );
		if ( $id ) {
			self::ficha( $id );
			return;
		}
		$estado = Inmo_Util::get( 'estado', 'vigente' );
		$filas  = Inmo_Arriendos::listar( $estado );
		echo '<div class="wrap inmo-wrap"><h1>Arriendos administrados</h1>';
		echo '<ul class="subsubsub"><li><a href="' . esc_url( admin_url( 'admin.php?page=inmo-arriendos' ) ) . '"' . ( 'vigente' === $estado ? ' class="current"' : '' ) . '>Vigentes</a> |</li><li><a href="' . esc_url( admin_url( 'admin.php?page=inmo-arriendos&estado=terminado' ) ) . '"' . ( 'terminado' === $estado ? ' class="current"' : '' ) . '>Terminados</a></li></ul>';
		echo '<p class="description" style="clear:both">Para iniciar la administración, abre una oportunidad de arriendo cerrada como ganada (o una propiedad en arriendo) y pulsa «Iniciar administración».</p>';
		echo '<table class="widefat striped"><thead><tr><th>Propiedad</th><th>Arrendatario</th><th>Propietario</th><th>Renta</th><th>Pago</th><th>Vigencia</th><th>Reajuste</th></tr></thead><tbody>';
		if ( ! $filas ) {
			echo '<tr><td colspan="7">Sin arriendos.</td></tr>';
		}
		foreach ( $filas as $a ) {
			echo '<tr><td><a href="' . esc_url( self::url( $a->id ) ) . '"><strong>' . esc_html( get_post_meta( $a->propiedad_id, '_inmo_codigo', true ) ) . '</strong> ' . esc_html( get_the_title( $a->propiedad_id ) ) . '</a></td>';
			echo '<td>' . esc_html( $a->arrendatario ) . '</td><td>' . esc_html( $a->beneficiario ) . '</td><td>' . esc_html( Inmo_Util::formatear_monto( $a->renta, $a->moneda ) ) . '</td><td>día ' . (int) $a->dia_pago . '</td>';
			echo '<td>' . esc_html( Inmo_Util::fecha( $a->fecha_inicio ) . ' – ' . ( $a->fecha_termino ? Inmo_Util::fecha( $a->fecha_termino ) : 'indefinido' ) ) . '</td>';
			echo '<td>' . ( Inmo_Arriendos::reajuste_pendiente( $a ) ? '<strong class="inmo-alerta-txt">Pendiente</strong>' : esc_html( $a->proximo_reajuste ? Inmo_Util::fecha( $a->proximo_reajuste ) : '—' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function form_nuevo() {
		$op  = absint( Inmo_Util::get( 'oportunidad' ) );
		$pid = absint( Inmo_Util::get( 'propiedad' ) );
		$s   = $op ? Inmo_Arriendos::sugerencia_desde_oportunidad( $op ) : null;
		if ( $op && ( ! $s || ! Inmo_Oportunidades::puede_ver( Inmo_Oportunidades::get( $op ) ) ) ) {
			echo '<div class="wrap"><p>Oportunidad no válida para administrar un arriendo.</p></div>';
			return;
		}
		if ( ! $s ) {
			$s = array( 'propiedad_id' => $pid, 'oportunidad_id' => 0, 'emision_id' => 0, 'arrendatario_id' => 0, 'beneficiario_id' => 0, 'renta' => get_post_meta( $pid, '_inmo_precio', true ), 'moneda' => get_post_meta( $pid, '_inmo_moneda', true ), 'dia_pago' => 5, 'fecha_inicio' => current_time( 'Y-m-d' ), 'fecha_termino' => '', 'reajuste' => get_post_meta( $pid, '_inmo_reajuste', true ), 'garantia' => '', 'comision_pct' => Inmo_Ajustes::get( 'admin_comision_pct' ) );
			$tit = Inmo_Contactos::titulares( $pid );
			$s['beneficiario_id'] = $tit ? (int) $tit[0]->id : 0;
		}
		$pid = (int) $s['propiedad_id'];
		list( $contactos ) = Inmo_Contactos::listar( array( 'por_pagina' => 300 ) );
		$reaj = Inmo_Esquema::campos()['reajuste']['opciones'];
		echo '<div class="wrap inmo-wrap"><h1>Iniciar administración del arriendo</h1>';
		echo '<p>Propiedad <strong>' . esc_html( get_post_meta( $pid, '_inmo_codigo', true ) . ' — ' . get_the_title( $pid ) ) . '</strong>. Datos sugeridos desde la oportunidad y el contrato; revísalos.</p>';
		self::abrir( 'crear', array( 'propiedad_id' => $pid, 'oportunidad_id' => $s['oportunidad_id'], 'emision_id' => $s['emision_id'] ) );
		echo '<div class="inmo-grid">';
		$sel = static function ( $name, $label, $actual ) use ( $contactos ) {
			$h = '<p><label for="n-' . $name . '">' . $label . '</label><select id="n-' . $name . '" name="' . $name . '" required><option value="">—</option>';
			foreach ( $contactos as $c ) {
				$h .= '<option value="' . (int) $c->id . '"' . selected( (int) $actual, (int) $c->id, false ) . '>' . esc_html( $c->nombre ) . '</option>';
			}
			return $h . '</select></p>';
		};
		echo $sel( 'arrendatario_id', 'Arrendatario', $s['arrendatario_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo $sel( 'beneficiario_id', 'Propietario que recibe las liquidaciones', $s['beneficiario_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><label for="n-renta">Renta mensual</label><input type="text" inputmode="decimal" id="n-renta" name="renta" value="' . esc_attr( $s['renta'] ) . '" required></p>';
		echo '<p><label for="n-moneda">Moneda</label><select id="n-moneda" name="moneda"><option value="CLP"' . selected( $s['moneda'], 'CLP', false ) . '>Pesos</option><option value="UF"' . selected( $s['moneda'], 'UF', false ) . '>UF (se cobra en pesos con la UF del día)</option></select></p>';
		echo '<p><label for="n-dia">Día de pago</label><input type="number" min="1" max="31" id="n-dia" name="dia_pago" value="' . (int) $s['dia_pago'] . '"></p>';
		echo '<p><label for="n-ini">Inicio</label><input type="date" id="n-ini" name="fecha_inicio" value="' . esc_attr( $s['fecha_inicio'] ) . '" required></p>';
		echo '<p><label for="n-fin">Término</label><input type="date" id="n-fin" name="fecha_termino" value="' . esc_attr( $s['fecha_termino'] ) . '"></p>';
		echo '<p><label for="n-reaj">Reajuste</label><select id="n-reaj" name="reajuste">';
		foreach ( $reaj as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['reajuste'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="n-gar">Garantía recibida</label><input type="text" inputmode="decimal" id="n-gar" name="garantia" value="' . esc_attr( $s['garantia'] ) . '"></p>';
		echo '<p><label for="n-com">Comisión de administración (% + IVA)</label><input type="text" inputmode="decimal" id="n-com" name="comision_pct" value="' . esc_attr( $s['comision_pct'] ) . '"></p>';
		echo '</div><p><button class="button button-primary button-large">Iniciar administración</button></p></form></div>';
	}

	private static function ficha( $id ) {
		$a    = Inmo_Arriendos::get( $id );
		$arr  = Inmo_Contactos::get( $a->arrendatario_id );
		$ben  = Inmo_Contactos::get( $a->beneficiario_id );
		$cod  = get_post_meta( $a->propiedad_id, '_inmo_codigo', true );
		$reaj = Inmo_Esquema::campos()['reajuste']['opciones'];
		echo '<div class="wrap inmo-wrap"><p><a href="' . esc_url( admin_url( 'admin.php?page=inmo-arriendos' ) ) . '">← Arriendos administrados</a></p>';
		echo '<h1>Arriendo ' . esc_html( $cod . ' — ' . get_the_title( $a->propiedad_id ) ) . ' ' . self::badge( $a->estado, Inmo_Arriendos::ESTADOS[ $a->estado ] ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( Inmo_Arriendos::reajuste_pendiente( $a ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>Reajuste pendiente</strong> desde el ' . esc_html( Inmo_Util::fecha( $a->proximo_reajuste ) ) . ' (' . esc_html( $reaj[ $a->reajuste ] ) . '). Ingresa la variación del IPC del período publicada por el INE.</p>';
			self::abrir( 'reajuste', array( 'id' => $id ) );
			echo '<p><label for="r-pct">IPC acumulado (%)</label> <input type="text" inputmode="decimal" id="r-pct" name="pct" size="6" required> <label for="r-f">Fuente</label> <input type="text" id="r-f" name="fuente" value="INE" size="10"> <button class="button button-primary">Aplicar reajuste</button></p></form></div>';
		}
		echo '<div class="inmo-columnas inmo-columnas--ficha"><section class="inmo-caja"><h2>Contrato</h2><ul class="inmo-resumen">';
		echo '<li><span>Arrendatario</span> <a href="' . esc_url( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $a->arrendatario_id ) ) . '">' . esc_html( $arr ? $arr->nombre : '' ) . '</a> ' . esc_html( $arr ? $arr->telefono : '' ) . '</li>';
		echo '<li><span>Propietario</span> <a href="' . esc_url( admin_url( 'admin.php?page=inmo-contactos&contacto=' . $a->beneficiario_id ) ) . '">' . esc_html( $ben ? $ben->nombre : '' ) . '</a>' . ( $ben && ! $ben->datos_bancarios ? ' <em>(sin datos bancarios)</em>' : '' ) . '</li>';
		echo '<li><span>Renta</span> <strong>' . esc_html( Inmo_Util::formatear_monto( $a->renta, $a->moneda ) ) . '</strong> · pago el día ' . (int) $a->dia_pago . '</li>';
		echo '<li><span>Vigencia</span> ' . esc_html( Inmo_Util::fecha( $a->fecha_inicio ) . ' – ' . ( $a->fecha_termino ? Inmo_Util::fecha( $a->fecha_termino ) : 'indefinido' ) ) . '</li>';
		echo '<li><span>Reajuste</span> ' . esc_html( $reaj[ $a->reajuste ] ?? $a->reajuste ) . ( $a->proximo_reajuste ? ' · próximo ' . esc_html( Inmo_Util::fecha( $a->proximo_reajuste ) ) : '' ) . '</li>';
		echo '<li><span>Garantía</span> ' . esc_html( ( null !== $a->garantia ? Inmo_Util::formatear_monto( $a->garantia, $a->moneda ) : '—' ) . ' (' . $a->garantia_estado . ')' ) . '</li>';
		echo '<li><span>Comisión</span> ' . esc_html( rtrim( rtrim( number_format( (float) $a->comision_pct, 2, ',', '.' ), '0' ), ',' ) . '% + IVA' ) . '</li>';
		if ( $a->emision_id ) {
			$e = Inmo_Contratos::get( $a->emision_id );
			echo '<li><span>Contrato</span> <a href="' . esc_url( admin_url( 'admin.php?page=inmo-documento&emision=' . $a->emision_id ) ) . '">' . esc_html( $e ? $e->numero : '' ) . '</a></li>';
		}
		echo '</ul>';

		// Gastos / abonos.
		echo '<h2>Gastos y abonos a cuenta del propietario</h2>';
		$movs = Inmo_Arriendos::movimientos_de( $id );
		if ( $movs ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $movs as $m ) {
				echo '<tr><td>' . esc_html( Inmo_Util::fecha( $m->fecha ) ) . '</td><td>' . esc_html( $m->concepto ) . ( $m->documento_id ? ' · <a href="' . esc_url( Inmo_Documentos::url( $m->documento_id ) ) . '">respaldo</a>' : '' ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( -$m->monto, 'CLP' ) ) . '</td><td>' . ( $m->liquidacion_id ? 'Liquidado' : 'Por liquidar' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		self::abrir( 'movimiento', array( 'id' => $id ), true );
		echo '<div class="inmo-grid"><p><label for="m-f">Fecha</label><input type="date" id="m-f" name="fecha" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '"></p><p><label for="m-c">Concepto</label><input type="text" id="m-c" name="concepto" required placeholder="Reparación calefont"></p><p><label for="m-m">Monto</label><input type="text" inputmode="numeric" id="m-m" name="monto" required></p><p><label for="m-t">Tipo</label><select id="m-t" name="tipo"><option value="gasto">Gasto (se descuenta)</option><option value="abono">Abono (se suma)</option></select></p><p><label for="m-d">Respaldo</label><input type="file" id="m-d" name="archivo" accept=".pdf,.jpg,.jpeg,.png"></p></div><p><button class="button">Registrar</button></p></form>';

		if ( 'vigente' === $a->estado ) {
			echo '<h2>Término del arriendo</h2>';
			self::abrir( 'terminar', array( 'id' => $id ) );
			echo '<p><label for="t-f">Fecha</label> <input type="date" id="t-f" name="fecha" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '"> <label for="t-g">Garantía</label> <select id="t-g" name="garantia_estado"><option value="devuelta">Devuelta</option><option value="aplicada">Aplicada a deudas o daños</option><option value="recibida">Aún retenida</option></select> <button class="button" onclick="return confirm(\'¿Terminar la administración de este arriendo?\');">Terminar</button></p></form>';
		}
		echo '</section><section class="inmo-caja"><h2>Cobros</h2>';
		self::abrir( 'generar_cobro', array( 'id' => $id ) );
		echo '<p><label for="g-p">Generar cobro del mes</label> <input type="month" id="g-p" name="periodo" value="' . esc_attr( current_time( 'Y-m' ) ) . '"> <button class="button">Generar</button> <span class="description">No duplica: un cobro por mes.</span></p></form>';
		self::tabla_cobros( Inmo_Arriendos::cobros_de( $id ), true );
		echo '</section><section class="inmo-caja"><h2>Liquidaciones al propietario</h2>';
		$previa = Inmo_Util::get( 'liq_previa' );
		echo '<form method="get"><input type="hidden" name="page" value="inmo-arriendos"><input type="hidden" name="id" value="' . (int) $id . '"><p><label for="l-p">Período</label> <input type="month" id="l-p" name="liq_previa" value="' . esc_attr( $previa ? $previa : current_time( 'Y-m' ) ) . '"> <button class="button">Previsualizar liquidación</button></p></form>';
		if ( $previa && preg_match( '/^\d{4}-\d{2}$/', $previa ) ) {
			$c = Inmo_Liquidaciones::calcular( $id, $previa );
			if ( is_wp_error( $c ) ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( $c->get_error_message() ) . '</p></div>';
			} else {
				echo '<table class="widefat inmo-liq" id="inmo-liq-previa"><tbody>';
				foreach ( $c['lineas'] as $l ) {
					echo '<tr><td>' . esc_html( $l['concepto'] ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( $l['monto'], 'CLP' ) ) . '</td></tr>';
				}
				echo '<tr class="total"><td>Total a transferir</td><td class="num" id="inmo-liq-neto">' . esc_html( Inmo_Util::formatear_monto( $c['neto'], 'CLP' ) ) . '</td></tr></tbody></table>';
				if ( $c['saldo_arrendatario'] ) {
					echo '<p class="description">Saldo impago del arrendatario: ' . esc_html( Inmo_Util::formatear_monto( $c['saldo_arrendatario'], 'CLP' ) ) . ' (se liquidará cuando se reciba).</p>';
				}
				self::abrir( 'emitir_liquidacion', array( 'id' => $id, 'periodo' => $previa ) );
				echo '<p><button class="button button-primary" id="inmo-emitir-liq"' . disabled( $c['sin_recaudacion'] && ! $c['gastos'], true, false ) . '>Emitir liquidación (PDF)</button></p></form>';
			}
		}
		self::tabla_liquidaciones( Inmo_Liquidaciones::de_arriendo( $id ) );
		echo '<h2>Historial</h2><ol class="inmo-historial">';
		foreach ( Inmo_Historial::de( 'propiedad_id', $a->propiedad_id, 40 ) as $h ) {
			if ( in_array( $h->accion, array( 'administracion', 'cobro', 'pago', 'reajuste_renta', 'gasto', 'liquidacion' ), true ) ) {
				echo '<li><time>' . esc_html( Inmo_Util::fecha( $h->creado, true ) ) . '</time> <strong>' . esc_html( Inmo_Historial::etiqueta( $h->accion ) ) . '</strong> — ' . esc_html( $h->detalle ) . '</li>';
			}
		}
		echo '</ol></section></div></div>';
	}

	public static function tabla_cobros( array $cobros, $acciones = false, $con_propiedad = false ) {
		if ( ! $cobros ) {
			echo '<p>Sin cobros.</p>';
			return;
		}
		echo '<table class="widefat striped inmo-cobros"><thead><tr><th>Período</th>' . ( $con_propiedad ? '<th>Propiedad / arrendatario</th>' : '' ) . '<th>Vence</th><th class="num">Total</th><th class="num">Pagado</th><th>Estado</th>' . ( $acciones ? '<th>Acción</th>' : '' ) . '</tr></thead><tbody>';
		foreach ( $cobros as $k ) {
			$c = Inmo_Arriendos::cobro( $k->id );
			echo '<tr id="cobro-' . (int) $c->id . '"><td><strong>' . esc_html( Inmo_Arriendos::periodo_texto( $c->periodo ) ) . '</strong>' . ( $c->nota ? '<br><span class="description">' . esc_html( $c->nota ) . '</span>' : '' );
			foreach ( $c->cargos as $cg ) {
				echo '<br><span class="description">+ ' . esc_html( $cg['concepto'] . ' ' . Inmo_Util::formatear_monto( $cg['monto'], 'CLP' ) ) . '</span>';
			}
			echo '</td>';
			if ( $con_propiedad ) {
				echo '<td><a href="' . esc_url( self::url( $c->arriendo_id ) ) . '">' . esc_html( get_post_meta( $k->propiedad_id, '_inmo_codigo', true ) ) . '</a> · ' . esc_html( $k->nombre ) . '</td>';
			}
			echo '<td>' . esc_html( Inmo_Util::fecha( $c->vence ) ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( $c->total, 'CLP' ) ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( $c->pagado, 'CLP' ) ) . '</td>';
			echo '<td>' . self::badge( 'cobro-' . $c->estado, Inmo_Arriendos::ESTADOS_COBRO[ $c->estado ] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $acciones ) {
				echo '<td>';
				$pagos = Inmo_Arriendos::pagos_de( $c->id );
				foreach ( $pagos as $pg ) {
					echo '<span class="description">' . esc_html( Inmo_Util::fecha( $pg->fecha ) . ' ' . Inmo_Util::formatear_monto( $pg->monto, 'CLP' ) . ' ' . Inmo_Arriendos::MEDIOS[ $pg->medio ] ) . ( $pg->documento_id ? ' · <a href="' . esc_url( Inmo_Documentos::url( $pg->documento_id ) ) . '">comprobante</a>' : '' ) . '</span><br>';
				}
				if ( ! in_array( $c->estado, array( 'pagado', 'anulado' ), true ) ) {
					$saldo = $c->total - $c->pagado;
					echo '<details><summary>Registrar pago</summary>';
					self::abrir( 'pago', array( 'cobro_id' => $c->id ), true );
					echo '<div class="inmo-ctrl"><label class="screen-reader-text" for="p-m-' . (int) $c->id . '">Monto</label><input type="text" inputmode="numeric" id="p-m-' . (int) $c->id . '" name="monto" value="' . (int) $saldo . '" size="9">';
					echo '<label class="screen-reader-text" for="p-f-' . (int) $c->id . '">Fecha</label><input type="date" id="p-f-' . (int) $c->id . '" name="fecha" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '">';
					echo '<label class="screen-reader-text" for="p-me-' . (int) $c->id . '">Medio</label><select id="p-me-' . (int) $c->id . '" name="medio">';
					foreach ( Inmo_Arriendos::MEDIOS as $mk => $ml ) {
						echo '<option value="' . esc_attr( $mk ) . '">' . esc_html( $ml ) . '</option>';
					}
					echo '</select><label class="screen-reader-text" for="p-r-' . (int) $c->id . '">Referencia</label><input type="text" id="p-r-' . (int) $c->id . '" name="referencia" placeholder="N° operación" size="10">';
					echo '<label class="screen-reader-text" for="p-a-' . (int) $c->id . '">Comprobante</label><input type="file" id="p-a-' . (int) $c->id . '" name="archivo" accept=".pdf,.jpg,.jpeg,.png"><button class="button button-small">Registrar pago</button></div></form></details>';
					echo '<details><summary>Agregar cargo</summary>';
					self::abrir( 'cargo', array( 'cobro_id' => $c->id ) );
					echo '<div class="inmo-ctrl"><label class="screen-reader-text" for="c-c-' . (int) $c->id . '">Concepto</label><input type="text" id="c-c-' . (int) $c->id . '" name="concepto" placeholder="Gastos comunes" required><label class="screen-reader-text" for="c-m-' . (int) $c->id . '">Monto</label><input type="text" inputmode="numeric" id="c-m-' . (int) $c->id . '" name="monto" size="8" required><button class="button button-small">Agregar</button></div></form></details>';
					if ( ! $c->pagado ) {
						echo '<details><summary>Anular</summary>';
						self::abrir( 'anular_cobro', array( 'cobro_id' => $c->id ) );
						echo '<div class="inmo-ctrl"><label class="screen-reader-text" for="a-m-' . (int) $c->id . '">Motivo</label><input type="text" id="a-m-' . (int) $c->id . '" name="motivo" placeholder="Motivo" required><button class="button button-small">Anular cobro</button></div></form></details>';
					}
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public static function tabla_liquidaciones( array $liqs, $con_propiedad = false ) {
		if ( ! $liqs ) {
			echo '<p>Sin liquidaciones emitidas.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Número</th><th>Período</th>' . ( $con_propiedad ? '<th>Propiedad / propietario</th>' : '' ) . '<th class="num">Recaudado</th><th class="num">Comisión + IVA</th><th class="num">Gastos</th><th class="num">Neto</th><th>Estado</th><th></th></tr></thead><tbody>';
		foreach ( $liqs as $l ) {
			echo '<tr><td><strong>' . esc_html( $l->numero ) . '</strong></td><td>' . esc_html( Inmo_Arriendos::periodo_texto( $l->periodo ) ) . '</td>';
			if ( $con_propiedad ) {
				echo '<td><a href="' . esc_url( self::url( $l->arriendo_id ) ) . '">' . esc_html( get_post_meta( $l->propiedad_id, '_inmo_codigo', true ) ) . '</a> · ' . esc_html( $l->beneficiario ) . '</td>';
			}
			echo '<td class="num">' . esc_html( Inmo_Util::formatear_monto( $l->ingresos, 'CLP' ) ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( $l->comision + $l->iva, 'CLP' ) ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( $l->gastos, 'CLP' ) ) . '</td><td class="num"><strong>' . esc_html( Inmo_Util::formatear_monto( $l->neto, 'CLP' ) ) . '</strong></td>';
			echo '<td>' . self::badge( 'liq-' . $l->estado, Inmo_Liquidaciones::ESTADOS[ $l->estado ] ) . ( 'pagada' === $l->estado ? '<br><span class="description">' . esc_html( Inmo_Util::fecha( $l->pagada_fecha ) . ' ' . $l->pagada_referencia ) . '</span>' : '' ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<a href="' . esc_url( Inmo_Documentos::url( $l->documento_id ) ) . '">PDF</a>';
			if ( 'emitida' === $l->estado ) {
				echo '<details><summary>Marcar transferida</summary>';
				self::abrir( 'liq_pagada', array( 'liq_id' => $l->id ) );
				echo '<div class="inmo-ctrl"><label class="screen-reader-text" for="lp-f-' . (int) $l->id . '">Fecha</label><input type="date" id="lp-f-' . (int) $l->id . '" name="fecha" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '"><label class="screen-reader-text" for="lp-r-' . (int) $l->id . '">Referencia</label><input type="text" id="lp-r-' . (int) $l->id . '" name="referencia" placeholder="N° transferencia" required><button class="button button-small">Guardar</button></div></form></details>';
				echo '<details><summary>Anular</summary>';
				self::abrir( 'liq_anular', array( 'liq_id' => $l->id ) );
				echo '<div class="inmo-ctrl"><label class="screen-reader-text" for="la-' . (int) $l->id . '">Motivo</label><input type="text" id="la-' . (int) $l->id . '" name="motivo" required><button class="button button-small">Anular</button></div></form></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* =====================================================================
	 * Cobranza y liquidaciones (vistas por período)
	 * =================================================================== */

	public static function pagina_cobranza() {
		$per    = Inmo_Util::get( 'periodo', current_time( 'Y-m' ) );
		$per    = preg_match( '/^\d{4}-\d{2}$/', $per ) ? $per : current_time( 'Y-m' );
		$estado = Inmo_Util::get( 'estado' );
		$cobros = Inmo_Arriendos::cobros_periodo( $per, $estado );
		$tot    = array( 'total' => 0, 'pagado' => 0 );
		foreach ( Inmo_Arriendos::cobros_periodo( $per ) as $k ) {
			if ( 'anulado' !== $k->estado ) {
				$tot['total']  += (int) $k->total;
				$tot['pagado'] += (int) $k->pagado;
			}
		}
		$morosos = Inmo_Arriendos::morosos();
		echo '<div class="wrap inmo-wrap"><h1>Cobranza de arriendos</h1>';
		echo '<form method="get" class="inmo-filtros"><input type="hidden" name="page" value="inmo-cobranza"><label for="cp">Período</label> <input type="month" id="cp" name="periodo" value="' . esc_attr( $per ) . '"> <label class="screen-reader-text" for="ce">Estado</label><select id="ce" name="estado"><option value="">Todos los estados</option>';
		foreach ( Inmo_Arriendos::ESTADOS_COBRO as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $estado, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <button class="button">Ver</button></form>';
		self::abrir( 'generar_periodo', array( 'periodo' => $per ) );
		echo '<p><button class="button button-primary">Generar cobros de ' . esc_html( Inmo_Arriendos::periodo_texto( $per ) ) . '</button> <span class="description">Para todos los arriendos vigentes. Se generan solos cada mes (tarea diaria).</span></p></form>';
		echo '<div class="inmo-kpis"><span class="inmo-kpi"><strong>' . esc_html( Inmo_Util::formatear_monto( $tot['total'], 'CLP' ) ) . '</strong> por cobrar en el mes</span><span class="inmo-kpi"><strong>' . esc_html( Inmo_Util::formatear_monto( $tot['pagado'], 'CLP' ) ) . '</strong> recaudado</span><span class="inmo-kpi"><strong>' . esc_html( Inmo_Util::formatear_monto( $tot['total'] - $tot['pagado'], 'CLP' ) ) . '</strong> pendiente</span><span class="inmo-kpi"><strong>' . count( $morosos ) . '</strong> cobros vencidos (todos los meses)</span></div>';
		self::tabla_cobros( $cobros, false, true );
		echo '<h2>Morosidad</h2>';
		if ( $morosos ) {
			echo '<table class="widefat striped" id="inmo-morosidad"><thead><tr><th>Propiedad</th><th>Arrendatario</th><th>Período</th><th>Venció</th><th class="num">Saldo</th><th>Días</th></tr></thead><tbody>';
			foreach ( $morosos as $k ) {
				$dias = (int) floor( ( strtotime( current_time( 'Y-m-d' ) ) - strtotime( $k->vence ) ) / DAY_IN_SECONDS );
				echo '<tr><td><a href="' . esc_url( self::url( $k->arriendo_id ) . '#cobro-' . $k->id ) . '">' . esc_html( get_post_meta( $k->propiedad_id, '_inmo_codigo', true ) ) . '</a></td><td>' . esc_html( $k->nombre . ' ' . $k->telefono ) . '</td><td>' . esc_html( Inmo_Arriendos::periodo_texto( $k->periodo ) ) . '</td><td>' . esc_html( Inmo_Util::fecha( $k->vence ) ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( $k->total - $k->pagado, 'CLP' ) ) . '</td><td>' . (int) $dias . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Sin cobros vencidos.</p>';
		}
		echo '</div>';
	}

	public static function pagina_liquidaciones() {
		$per = Inmo_Util::get( 'periodo' );
		$per = preg_match( '/^\d{4}-\d{2}$/', $per ) ? $per : '';
		echo '<div class="wrap inmo-wrap"><h1>Liquidaciones a propietarios</h1>';
		echo '<form method="get" class="inmo-filtros"><input type="hidden" name="page" value="inmo-liquidaciones"><label for="lp">Período</label> <input type="month" id="lp" name="periodo" value="' . esc_attr( $per ) . '"> <button class="button">Filtrar</button></form>';
		self::abrir( 'liquidar_periodo', array( 'periodo' => $per ? $per : current_time( 'Y-m' ) ) );
		echo '<p><button class="button button-primary">Emitir liquidaciones de ' . esc_html( Inmo_Arriendos::periodo_texto( $per ? $per : current_time( 'Y-m' ) ) ) . '</button> <span class="description">Una por arriendo con pagos o gastos por liquidar; los ya liquidados se omiten.</span></p></form>';
		self::tabla_liquidaciones( Inmo_Liquidaciones::listar( $per ), true );
		echo '</div>';
	}

	/* =====================================================================
	 * Acciones
	 * =================================================================== */

	public static function procesar() {
		check_admin_referer( 'inmo_admin_arriendo' );
		if ( ! current_user_can( 'inmo_gestionar' ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		$accion = Inmo_Util::post( 'accion' );
		$volver = isset( $_POST['_volver'] ) ? esc_url_raw( wp_unslash( $_POST['_volver'] ) ) : admin_url( 'admin.php?page=inmo-arriendos' );
		$msg    = 'Listo.';
		$r      = true;
		// Arriendo afectado (para verificar permisos por propiedad).
		$a = null;
		if ( Inmo_Util::post( 'id' ) ) {
			$a = Inmo_Arriendos::get( absint( Inmo_Util::post( 'id' ) ) );
		} elseif ( Inmo_Util::post( 'cobro_id' ) ) {
			$c = Inmo_Arriendos::cobro( absint( Inmo_Util::post( 'cobro_id' ) ) );
			$a = $c ? Inmo_Arriendos::get( $c->arriendo_id ) : null;
		} elseif ( Inmo_Util::post( 'liq_id' ) ) {
			$l = Inmo_Liquidaciones::get( absint( Inmo_Util::post( 'liq_id' ) ) );
			$a = $l ? Inmo_Arriendos::get( $l->arriendo_id ) : null;
		}
		$globales = array( 'crear', 'generar_periodo', 'liquidar_periodo' );
		if ( ! in_array( $accion, $globales, true ) && ! Inmo_Arriendos::puede_ver( $a ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- archivos validados en Inmo_Documentos.
		switch ( $accion ) {
			case 'crear':
				$pid = absint( Inmo_Util::post( 'propiedad_id' ) );
				if ( ! Inmo_Permisos::puede_ver_privado( $pid ) ) {
					wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
				}
				$r = Inmo_Arriendos::crear(
					array(
						'propiedad_id'    => $pid,
						'oportunidad_id'  => absint( Inmo_Util::post( 'oportunidad_id' ) ),
						'emision_id'      => absint( Inmo_Util::post( 'emision_id' ) ),
						'arrendatario_id' => absint( Inmo_Util::post( 'arrendatario_id' ) ),
						'beneficiario_id' => absint( Inmo_Util::post( 'beneficiario_id' ) ),
						'renta'           => Inmo_Util::post( 'renta' ),
						'moneda'          => Inmo_Util::post( 'moneda' ),
						'dia_pago'        => absint( Inmo_Util::post( 'dia_pago' ) ),
						'fecha_inicio'    => Inmo_Util::post( 'fecha_inicio' ),
						'fecha_termino'   => Inmo_Util::post( 'fecha_termino' ),
						'reajuste'        => Inmo_Util::post( 'reajuste' ),
						'garantia'        => Inmo_Util::post( 'garantia' ),
						'comision_pct'    => Inmo_Util::post( 'comision_pct' ),
					)
				);
				if ( ! is_wp_error( $r ) ) {
					$volver = self::url( $r );
					$msg    = 'Administración iniciada.';
				}
				break;
			case 'reajuste':
				$r   = Inmo_Arriendos::aplicar_reajuste( $a->id, Inmo_Util::post( 'pct' ), Inmo_Util::post( 'fuente', 'INE' ) );
				$msg = 'Reajuste aplicado: rige para los próximos cobros.';
				break;
			case 'generar_cobro':
				$r   = Inmo_Arriendos::generar_cobro( $a->id, Inmo_Util::post( 'periodo' ) );
				$r   = null === $r ? new WP_Error( 'x', 'El arriendo no está vigente en ese período.' ) : $r;
				$msg = 'Cobro listo.';
				break;
			case 'generar_periodo':
				list( $nuevos, $previos ) = Inmo_Arriendos::generar_periodo( Inmo_Util::post( 'periodo' ) );
				$msg = sprintf( '%d cobro(s) generado(s); %d ya existían.', $nuevos, $previos );
				break;
			case 'pago':
				$r   = Inmo_Arriendos::registrar_pago( absint( Inmo_Util::post( 'cobro_id' ) ), Inmo_Util::post( 'monto' ), Inmo_Util::post( 'fecha' ), Inmo_Util::post( 'medio' ), Inmo_Util::post( 'referencia' ), ! empty( $_FILES['archivo']['name'] ) ? $_FILES['archivo'] : null );
				$msg = 'Pago registrado.';
				break;
			case 'cargo':
				$r = Inmo_Arriendos::agregar_cargo( absint( Inmo_Util::post( 'cobro_id' ) ), Inmo_Util::post( 'concepto' ), Inmo_Util::post( 'monto' ) );
				break;
			case 'anular_cobro':
				$r = Inmo_Arriendos::anular_cobro( absint( Inmo_Util::post( 'cobro_id' ) ), Inmo_Util::post( 'motivo' ) );
				break;
			case 'movimiento':
				$r   = Inmo_Arriendos::registrar_movimiento( $a->id, Inmo_Util::post( 'fecha' ), Inmo_Util::post( 'concepto' ), Inmo_Util::post( 'monto' ), Inmo_Util::post( 'tipo' ), ! empty( $_FILES['archivo']['name'] ) ? $_FILES['archivo'] : null );
				$msg = 'Movimiento registrado.';
				break;
			case 'terminar':
				$r = Inmo_Arriendos::terminar( $a->id, Inmo_Util::post( 'fecha' ), Inmo_Util::post( 'garantia_estado' ) );
				break;
			case 'emitir_liquidacion':
				$r = Inmo_Liquidaciones::emitir( $a->id, Inmo_Util::post( 'periodo' ) );
				if ( ! is_wp_error( $r ) ) {
					$msg = 'Liquidación ' . Inmo_Liquidaciones::get( $r )->numero . ' emitida.';
				}
				break;
			case 'liquidar_periodo':
				$per = Inmo_Util::post( 'periodo' );
				$n   = 0;
				$err = array();
				foreach ( Inmo_Arriendos::listar( '' ) as $x ) {
					$c = Inmo_Liquidaciones::calcular( $x->id, $per );
					if ( is_wp_error( $c ) || ( $c['sin_recaudacion'] && ! $c['gastos'] ) ) {
						continue;
					}
					$e = Inmo_Liquidaciones::emitir( $x->id, $per );
					if ( is_wp_error( $e ) ) {
						$err[] = get_post_meta( $x->propiedad_id, '_inmo_codigo', true ) . ': ' . $e->get_error_message();
					} else {
						$n++;
					}
				}
				$msg = $n . ' liquidación(es) emitida(s).' . ( $err ? ' Errores: ' . implode( '; ', $err ) : '' );
				break;
			case 'liq_pagada':
				$r = Inmo_Liquidaciones::marcar_pagada( absint( Inmo_Util::post( 'liq_id' ) ), Inmo_Util::post( 'fecha' ), Inmo_Util::post( 'referencia' ) );
				break;
			case 'liq_anular':
				$r = Inmo_Liquidaciones::anular( absint( Inmo_Util::post( 'liq_id' ) ), Inmo_Util::post( 'motivo' ) );
				break;
		}
		// phpcs:enable
		Inmo_Admin::avisar( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? esc_html( $r->get_error_message() ) : esc_html( $msg ) );
		wp_safe_redirect( $volver );
		exit;
	}
}
