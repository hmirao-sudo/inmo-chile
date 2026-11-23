<?php
/**
 * Liquidaciones mensuales al propietario: lo recaudado del período, menos la comisión de administración (+ IVA)
 * y los gastos a su cargo, más abonos. Al emitir se congela el detalle y se genera un PDF inmutable.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Liquidaciones {

	const ESTADOS = array(
		'emitida' => 'Emitida (por transferir)',
		'pagada'  => 'Transferida al propietario',
		'anulada' => 'Anulada',
	);

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_liquidaciones';
	}

	/**
	 * Calcula (sin guardar) la liquidación de un arriendo y período.
	 *
	 * @return array|WP_Error
	 */
	public static function calcular( $arriendo_id, $periodo ) {
		global $wpdb;
		$a = Inmo_Arriendos::get( $arriendo_id );
		if ( ! $a ) {
			return new WP_Error( 'inmo_liq', 'Arriendo no encontrado.' );
		}
		$ya = self::vigente( $arriendo_id, $periodo );
		if ( $ya ) {
			return new WP_Error( 'inmo_liq', 'El período ya tiene la liquidación ' . $ya->numero . '.' );
		}
		$fin_p    = gmdate( 'Y-m-t', strtotime( $periodo . '-01' ) );
		$pagos    = $wpdb->get_results( $wpdb->prepare( 'SELECT g.*, k.periodo, k.total, k.pagado AS pagado_cobro FROM ' . $wpdb->prefix . 'inmo_pagos g JOIN ' . $wpdb->prefix . "inmo_cobros k ON k.id = g.cobro_id WHERE k.arriendo_id = %d AND g.liquidacion_id = 0 AND g.fecha <= %s AND k.estado <> 'anulado' ORDER BY g.fecha, g.id", $arriendo_id, $fin_p ) ); // phpcs:ignore
		$lineas   = array();
		$ingresos = 0;
		$pago_ids = array();
		foreach ( $pagos as $g ) {
			$ingresos  += (int) $g->monto;
			$pago_ids[] = (int) $g->id;
			$lineas[]   = array( 'tipo' => 'ingreso', 'concepto' => 'Arriendo ' . Inmo_Arriendos::periodo_texto( $g->periodo ) . ' — pago del ' . Inmo_Util::fecha( $g->fecha ) . ( $g->periodo !== $periodo ? ' (período anterior)' : '' ), 'monto' => (int) $g->monto );
		}
		$pendiente = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(total - pagado) FROM ' . $wpdb->prefix . "inmo_cobros WHERE arriendo_id = %d AND periodo <= %s AND estado IN ('pendiente','parcial','vencido')", $arriendo_id, $periodo ) ); // phpcs:ignore
		$pct      = (float) $a->comision_pct;
		$iva_pct  = (float) Inmo_Ajustes::get( 'iva_pct', 19 );
		$comision = (int) round( $ingresos * $pct / 100 );
		$iva      = (int) round( $comision * $iva_pct / 100 );
		if ( $comision ) {
			$lineas[] = array( 'tipo' => 'descuento', 'concepto' => 'Comisión de administración ' . self::pct( $pct ) . '%', 'monto' => -$comision );
			$lineas[] = array( 'tipo' => 'descuento', 'concepto' => 'IVA ' . self::pct( $iva_pct ) . '% sobre la comisión', 'monto' => -$iva );
		}
		$gastos = 0;
		$movs   = array();
		foreach ( Inmo_Arriendos::movimientos_de( $arriendo_id, true ) as $m ) {
			if ( $m->fecha > $fin_p ) {
				continue; // Se liquida en el período en que ocurre.
			}
			$gastos  += (int) $m->monto;
			$movs[]   = (int) $m->id;
			$lineas[] = array( 'tipo' => $m->monto > 0 ? 'gasto' : 'abono', 'concepto' => $m->concepto . ' (' . Inmo_Util::fecha( $m->fecha ) . ')', 'monto' => -(int) $m->monto );
		}
		$neto = $ingresos - $comision - $iva - $gastos;
		return array(
			'arriendo_id'     => (int) $arriendo_id,
			'periodo'         => $periodo,
			'pagos'           => $pago_ids,
			'saldo_arrendatario' => $pendiente,
			'beneficiario_id' => (int) $a->beneficiario_id,
			'lineas'          => $lineas,
			'movimientos'     => $movs,
			'ingresos'        => $ingresos,
			'comision'        => $comision,
			'iva'             => $iva,
			'gastos'          => $gastos,
			'neto'            => $neto,
			'sin_recaudacion' => 0 === $ingresos,
		);
	}

	private static function pct( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, ',', '.' ), '0' ), ',' );
	}

	public static function vigente( $arriendo_id, $periodo ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . " WHERE arriendo_id = %d AND periodo = %s AND estado <> 'anulada' LIMIT 1", $arriendo_id, $periodo ) ); // phpcs:ignore
	}

	/**
	 * Emite la liquidación: numera, congela el detalle, marca los movimientos y genera el PDF.
	 *
	 * @return int|WP_Error
	 */
	public static function emitir( $arriendo_id, $periodo ) {
		global $wpdb;
		$c = self::calcular( $arriendo_id, $periodo );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( $c['sin_recaudacion'] && ! $c['gastos'] ) {
			return new WP_Error( 'inmo_liq', 'No hay pagos ni movimientos que liquidar en ' . Inmo_Arriendos::periodo_texto( $periodo ) . '.' );
		}
		$a     = Inmo_Arriendos::get( $arriendo_id );
		$b     = Inmo_Contactos::get( $a->beneficiario_id );
		$pref  = 'LIQ-' . substr( $periodo, 0, 4 ) . '-';
		$ult   = $wpdb->get_var( $wpdb->prepare( 'SELECT numero FROM ' . self::tabla() . ' WHERE numero LIKE %s ORDER BY id DESC LIMIT 1', $wpdb->esc_like( $pref ) . '%' ) ); // phpcs:ignore
		$num   = $pref . str_pad( (string) ( $ult ? (int) substr( $ult, strlen( $pref ) ) + 1 : 1 ), 4, '0', STR_PAD_LEFT );
		$c['numero']       = $num;
		$c['propiedad']    = get_post_meta( $a->propiedad_id, '_inmo_codigo', true ) . ' — ' . get_the_title( $a->propiedad_id );
		$c['beneficiario'] = $b ? $b->nombre . ( $b->rut ? ', RUT ' . Inmo_Util::formatear_rut( $b->rut ) : '' ) : '';
		$c['banco']        = $b && $b->datos_bancarios ? $b->datos_bancarios : '';
		$arr               = Inmo_Contactos::get( $a->arrendatario_id );
		$c['arrendatario'] = $arr ? $arr->nombre : '';
		$html              = self::html( $c );
		$pdf               = Inmo_Pdf::generar( $html );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		$doc = Inmo_Documentos::guardar_contenido( $pdf, 'pdf', $a->propiedad_id, 'contrato', strtolower( $num ) . '.pdf', 'application/pdf', $a->beneficiario_id, 'liquidacion' );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$wpdb->insert(
			self::tabla(),
			array(
				'numero'          => $num,
				'arriendo_id'     => $arriendo_id,
				'beneficiario_id' => (int) $a->beneficiario_id,
				'periodo'         => $periodo,
				'detalle'         => wp_json_encode( $c ),
				'ingresos'        => $c['ingresos'],
				'comision'        => $c['comision'],
				'iva'             => $c['iva'],
				'gastos'          => $c['gastos'],
				'neto'            => $c['neto'],
				'estado'          => 'emitida',
				'documento_id'    => (int) $doc,
				'sha256'          => hash( 'sha256', $pdf ),
				'user_id'         => get_current_user_id(),
				'creado'          => Inmo_Util::ahora(),
				'actualizado'     => Inmo_Util::ahora(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $c['movimientos'] ) {
			$wpdb->query( 'UPDATE ' . $wpdb->prefix . 'inmo_movimientos SET liquidacion_id = ' . $id . ' WHERE id IN (' . implode( ',', array_map( 'intval', $c['movimientos'] ) ) . ')' ); // phpcs:ignore
		}
		if ( $c['pagos'] ) {
			$wpdb->query( 'UPDATE ' . $wpdb->prefix . 'inmo_pagos SET liquidacion_id = ' . $id . ' WHERE id IN (' . implode( ',', array_map( 'intval', $c['pagos'] ) ) . ')' ); // phpcs:ignore
		}
		Inmo_Historial::registrar( 'liquidacion', $num . ' ' . Inmo_Arriendos::periodo_texto( $periodo ) . ': neto ' . Inmo_Util::formatear_monto( $c['neto'], 'CLP' ), array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $a->beneficiario_id ) );
		if ( $b && $b->email ) {
			$abs = Inmo_Documentos::ruta_absoluta( Inmo_Documentos::get( $doc ) );
			Inmo_Correo::enviar_simple( $b->email, 'Liquidación ' . Inmo_Arriendos::periodo_texto( $periodo ) . ' · ' . $num, "Hola {$b->nombre}:\n\nAdjuntamos la liquidación de " . Inmo_Arriendos::periodo_texto( $periodo ) . ' de tu propiedad ' . get_post_meta( $a->propiedad_id, '_inmo_codigo', true ) . '. Monto a transferir: ' . Inmo_Util::formatear_monto( $c['neto'], 'CLP' ) . '.', array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $b->id ), $abs ? array( $abs ) : array() );
		}
		return $id;
	}

	public static function html( array $c ) {
		$filas = '';
		foreach ( $c['lineas'] as $l ) {
			$filas .= '<tr><td>' . esc_html( $l['concepto'] ) . '</td><td class="m">' . esc_html( Inmo_Util::formatear_monto( $l['monto'], 'CLP' ) ) . '</td></tr>';
		}
		$css  = 'body{font-family:DejaVu Sans,sans-serif;font-size:10pt;color:#111}h1{font-size:15pt;margin:0 0 4pt}table{width:100%;border-collapse:collapse;margin:10pt 0}td,th{border-bottom:1px solid #ccc;padding:5pt;text-align:left}.m{text-align:right;white-space:nowrap}.t td{font-weight:bold;border-top:2px solid #111;font-size:12pt}.p{color:#555}';
		$cuer = '<h1>Liquidación ' . esc_html( $c['numero'] ) . '</h1><p class="p">' . esc_html( Inmo_Ajustes::get( 'marca_nombre' ) . ' · ' . Inmo_Ajustes::get( 'direccion_oficina' ) ) . '</p>'
			. '<p><strong>Período:</strong> ' . esc_html( Inmo_Arriendos::periodo_texto( $c['periodo'] ) ) . '<br><strong>Propiedad:</strong> ' . esc_html( $c['propiedad'] ) . '<br><strong>Propietario:</strong> ' . esc_html( $c['beneficiario'] ) . '<br><strong>Arrendatario:</strong> ' . esc_html( $c['arrendatario'] ) . '</p>'
			. '<table><thead><tr><th>Concepto</th><th class="m">Monto</th></tr></thead><tbody>' . $filas . '<tr class="t"><td>Total a transferir al propietario</td><td class="m">' . esc_html( Inmo_Util::formatear_monto( $c['neto'], 'CLP' ) ) . '</td></tr></tbody></table>'
			. ( ! empty( $c['saldo_arrendatario'] ) ? '<p><strong>Saldo impago del arrendatario al cierre:</strong> ' . esc_html( Inmo_Util::formatear_monto( $c['saldo_arrendatario'], 'CLP' ) ) . ' (se liquidará cuando se reciba).</p>' : '' )
			. ( $c['banco'] ? '<p><strong>Cuenta de destino:</strong> ' . esc_html( $c['banco'] ) . '</p>' : '' )
			. '<p class="p">Emitida el ' . esc_html( Inmo_Util::fecha( Inmo_Util::ahora(), true ) ) . '. Los respaldos de gastos están disponibles en el expediente de la propiedad.</p>';
		return '<!doctype html><html lang="es"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . $cuer . '</body></html>';
	}

	public static function get( $id ) {
		global $wpdb;
		$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( $l ) {
			$l->detalle = (array) json_decode( $l->detalle, true );
		}
		return $l;
	}

	public static function de_arriendo( $arriendo_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE arriendo_id = %d ORDER BY periodo DESC, id DESC', $arriendo_id ) ); // phpcs:ignore
	}

	public static function listar( $periodo = '', $estado = '' ) {
		global $wpdb;
		$w = Inmo_Permisos::ve_todo() ? '1=1' : $wpdb->prepare( 'p.post_author = %d', get_current_user_id() );
		if ( $periodo ) {
			$w .= $wpdb->prepare( ' AND l.periodo = %s', $periodo );
		}
		if ( isset( self::ESTADOS[ $estado ] ) ) {
			$w .= $wpdb->prepare( ' AND l.estado = %s', $estado );
		}
		return $wpdb->get_results( 'SELECT l.*, a.propiedad_id, c.nombre AS beneficiario FROM ' . self::tabla() . ' l JOIN ' . Inmo_Arriendos::tabla() . " a ON a.id = l.arriendo_id JOIN {$wpdb->posts} p ON p.ID = a.propiedad_id LEFT JOIN " . Inmo_Contactos::tabla() . " c ON c.id = l.beneficiario_id WHERE {$w} ORDER BY l.periodo DESC, l.id DESC" ); // phpcs:ignore
	}

	public static function marcar_pagada( $id, $fecha, $referencia ) {
		global $wpdb;
		$l = self::get( $id );
		if ( ! $l || 'emitida' !== $l->estado ) {
			return new WP_Error( 'inmo_liq', 'Solo se marcan como transferidas las liquidaciones emitidas.' );
		}
		$wpdb->update( self::tabla(), array( 'estado' => 'pagada', 'pagada_fecha' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $fecha ) ? $fecha : current_time( 'Y-m-d' ), 'pagada_referencia' => sanitize_text_field( $referencia ), 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $id ) );
		$a = Inmo_Arriendos::get( $l->arriendo_id );
		Inmo_Historial::registrar( 'liquidacion', $l->numero . ' transferida (' . sanitize_text_field( $referencia ) . ')', array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $l->beneficiario_id ) );
		return true;
	}

	/** Anula una liquidación emitida (no transferida): libera el cobro y los gastos para liquidarlos de nuevo. */
	public static function anular( $id, $motivo ) {
		global $wpdb;
		$l = self::get( $id );
		if ( ! $l || 'emitida' !== $l->estado ) {
			return new WP_Error( 'inmo_liq', 'Solo se anulan liquidaciones emitidas y no transferidas.' );
		}
		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'inmo_liq', 'Indica el motivo.' );
		}
		$wpdb->update( self::tabla(), array( 'estado' => 'anulada', 'motivo_anulacion' => sanitize_text_field( $motivo ), 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $id ) );
		$wpdb->update( $wpdb->prefix . 'inmo_movimientos', array( 'liquidacion_id' => 0 ), array( 'liquidacion_id' => $id ) );
		$wpdb->update( $wpdb->prefix . 'inmo_pagos', array( 'liquidacion_id' => 0 ), array( 'liquidacion_id' => $id ) );
		$a = Inmo_Arriendos::get( $l->arriendo_id );
		Inmo_Historial::registrar( 'liquidacion', $l->numero . ' anulada: ' . $motivo, array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $l->beneficiario_id ) );
		return true;
	}

	public static function integra( $l ) {
		$doc = Inmo_Documentos::get( $l->documento_id );
		$abs = $doc ? Inmo_Documentos::ruta_absoluta( $doc ) : '';
		return $abs && hash_file( 'sha256', $abs ) === $l->sha256;
	}
}
