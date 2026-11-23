<?php
/**
 * Administración de arriendos: contrato vigente, cobros mensuales, pagos, cargos, gastos, reajustes y morosidad.
 * Los montos se cobran en pesos; una renta en UF se convierte con la UF del día de vencimiento (fuente y fecha guardadas).
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Arriendos {

	const ESTADOS = array(
		'vigente'   => 'Vigente',
		'terminado' => 'Terminado',
	);

	const ESTADOS_COBRO = array(
		'pendiente' => 'Pendiente',
		'parcial'   => 'Pago parcial',
		'pagado'    => 'Pagado',
		'vencido'   => 'Vencido',
		'anulado'   => 'Anulado',
	);

	const MEDIOS = array(
		'transferencia' => 'Transferencia',
		'deposito'      => 'Depósito',
		'efectivo'      => 'Efectivo',
		'cheque'        => 'Cheque',
		'otro'          => 'Otro',
	);

	const REAJUSTE_MESES = array(
		'ipc_semestral' => 6,
		'ipc_anual'     => 12,
	);

	public static function init() {
		add_action( 'inmo_diario', array( __CLASS__, 'tarea_diaria' ) );
		add_action( 'init', array( __CLASS__, 'programar' ) );
	}

	public static function programar() {
		if ( ! wp_next_scheduled( 'inmo_diario' ) ) {
			$t = date_create_immutable( 'tomorrow 07:00', wp_timezone() );
			wp_schedule_event( $t->getTimestamp(), 'daily', 'inmo_diario' );
		}
	}

	private static function t( $n ) {
		global $wpdb;
		return $wpdb->prefix . 'inmo_' . $n;
	}

	public static function tabla() {
		return self::t( 'arriendos' );
	}

	/* =====================================================================
	 * Contrato administrado
	 * =================================================================== */

	/** Datos sugeridos para iniciar la administración desde una oportunidad de arriendo. */
	public static function sugerencia_desde_oportunidad( $oportunidad_id ) {
		$o = Inmo_Oportunidades::get( $oportunidad_id );
		if ( ! $o || ! $o->propiedad_id || 'arriendo' !== $o->operacion ) {
			return null;
		}
		$pid   = (int) $o->propiedad_id;
		$cond  = $o->condiciones;
		$renta = isset( $cond['precio_acordado'] ) ? $cond['precio_acordado'] : get_post_meta( $pid, '_inmo_precio', true );
		$mon   = ! empty( $cond['moneda_acordada'] ) ? $cond['moneda_acordada'] : get_post_meta( $pid, '_inmo_moneda', true );
		$gm    = isset( $cond['garantia_meses'] ) ? $cond['garantia_meses'] : get_post_meta( $pid, '_inmo_garantia_meses', true );
		$plazo = isset( $cond['plazo_meses'] ) ? (int) $cond['plazo_meses'] : (int) get_post_meta( $pid, '_inmo_vigencia_meses', true );
		$ini   = ! empty( $cond['fecha_inicio'] ) ? $cond['fecha_inicio'] : current_time( 'Y-m-d' );
		$tit   = Inmo_Contactos::titulares( $pid );
		$emis  = array_values( array_filter( Inmo_Contratos::de_oportunidad( $oportunidad_id ), static function ( $e ) { return 'arriendo' === $e->tipo && 'emitido' === $e->estado_emision; } ) );
		$priv  = Inmo_Privado::get( $pid );
		return array(
			'propiedad_id'    => $pid,
			'oportunidad_id'  => (int) $oportunidad_id,
			'emision_id'      => $emis ? (int) $emis[0]->id : 0,
			'arrendatario_id' => (int) $o->contacto_id,
			'beneficiario_id' => $tit ? (int) $tit[0]->id : 0,
			'renta'           => $renta,
			'moneda'          => $mon ? $mon : 'CLP',
			'dia_pago'        => ! empty( $cond['dia_pago'] ) ? (int) $cond['dia_pago'] : 5,
			'fecha_inicio'    => $ini,
			'fecha_termino'   => $plazo ? gmdate( 'Y-m-d', strtotime( $ini . ' +' . $plazo . ' months -1 day' ) ) : '',
			'reajuste'        => get_post_meta( $pid, '_inmo_reajuste', true ) ? get_post_meta( $pid, '_inmo_reajuste', true ) : 'sin_reajuste',
			'garantia'        => ( '' !== (string) $gm && '' !== (string) $renta ) ? round( (float) $gm * (float) $renta, 2 ) : '',
			'comision_pct'    => 'corretaje_admin' === $priv['mandato_tipo'] && '' !== (string) $priv['comision_pct'] && (float) $priv['comision_pct'] < 30 ? $priv['comision_pct'] : Inmo_Ajustes::get( 'admin_comision_pct', 7 ),
		);
	}

	/**
	 * @return int|WP_Error
	 */
	public static function crear( array $d ) {
		global $wpdb;
		$pid = (int) $d['propiedad_id'];
		if ( ! $pid || 'arriendo' !== Inmo_Propiedades::termino( $pid, Inmo_Propiedades::TAX_OPERACION ) ) {
			return new WP_Error( 'inmo_arriendo', 'La propiedad debe estar en arriendo.' );
		}
		if ( self::vigente_de_propiedad( $pid ) ) {
			return new WP_Error( 'inmo_arriendo', 'La propiedad ya tiene un arriendo administrado vigente.' );
		}
		$renta = Inmo_Util::parse_numero( $d['renta'] );
		$ini   = (string) $d['fecha_inicio'];
		$err   = array();
		if ( '' === $renta || $renta <= 0 ) {
			$err[] = 'renta';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ini ) ) {
			$err[] = 'fecha de inicio';
		}
		if ( empty( $d['arrendatario_id'] ) || ! Inmo_Contactos::get( (int) $d['arrendatario_id'] ) ) {
			$err[] = 'arrendatario';
		}
		if ( empty( $d['beneficiario_id'] ) || ! Inmo_Contactos::get( (int) $d['beneficiario_id'] ) ) {
			$err[] = 'propietario que recibe las liquidaciones';
		}
		if ( $err ) {
			return new WP_Error( 'inmo_arriendo', 'Falta: ' . implode( ', ', $err ) . '.' );
		}
		$reaj = isset( Inmo_Esquema::campos()['reajuste']['opciones'][ $d['reajuste'] ] ) ? $d['reajuste'] : 'sin_reajuste';
		$dia  = max( 1, min( 31, (int) $d['dia_pago'] ) );
		$wpdb->insert(
			self::tabla(),
			array(
				'propiedad_id'     => $pid,
				'oportunidad_id'   => (int) ( $d['oportunidad_id'] ?? 0 ),
				'emision_id'       => (int) ( $d['emision_id'] ?? 0 ),
				'arrendatario_id'  => (int) $d['arrendatario_id'],
				'beneficiario_id'  => (int) $d['beneficiario_id'],
				'renta'            => $renta,
				'moneda'           => 'UF' === $d['moneda'] ? 'UF' : 'CLP',
				'dia_pago'         => $dia,
				'fecha_inicio'     => $ini,
				'fecha_termino'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $d['fecha_termino'] ?? '' ) ) ? $d['fecha_termino'] : null,
				'reajuste'         => $reaj,
				'proximo_reajuste' => isset( self::REAJUSTE_MESES[ $reaj ] ) ? gmdate( 'Y-m-d', strtotime( $ini . ' +' . self::REAJUSTE_MESES[ $reaj ] . ' months' ) ) : null,
				'garantia'         => '' !== Inmo_Util::parse_numero( $d['garantia'] ?? '' ) ? Inmo_Util::parse_numero( $d['garantia'] ) : null,
				'garantia_estado'  => 'recibida',
				'comision_pct'     => (float) Inmo_Util::parse_numero( $d['comision_pct'] ?? Inmo_Ajustes::get( 'admin_comision_pct', 7 ) ),
				'estado'           => 'vigente',
				'creado'           => Inmo_Util::ahora(),
				'actualizado'      => Inmo_Util::ahora(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			Inmo_Contactos::upsert( array( 'id' => (int) $d['arrendatario_id'], 'roles' => array( 'arrendatario' ) ) );
			Inmo_Historial::registrar( 'administracion', 'Inicio de la administración: renta ' . Inmo_Util::formatear_monto( $renta, 'UF' === $d['moneda'] ? 'UF' : 'CLP' ) . ', pago el día ' . $dia, array( 'propiedad_id' => $pid, 'contacto_id' => (int) $d['arrendatario_id'], 'oportunidad_id' => (int) ( $d['oportunidad_id'] ?? 0 ) ) );
		}
		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	public static function vigente_de_propiedad( $pid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . " WHERE propiedad_id = %d AND estado = 'vigente' ORDER BY id DESC LIMIT 1", $pid ) ); // phpcs:ignore
	}

	public static function de_propiedad( $pid ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE propiedad_id = %d ORDER BY id DESC', $pid ) ); // phpcs:ignore
	}

	public static function de_beneficiario( $contacto_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE beneficiario_id = %d ORDER BY id DESC', $contacto_id ) ); // phpcs:ignore
	}

	public static function puede_ver( $a ) {
		return $a && current_user_can( 'inmo_gestionar' ) && Inmo_Permisos::puede_ver_privado( $a->propiedad_id );
	}

	public static function listar( $estado = 'vigente' ) {
		global $wpdb;
		$w = Inmo_Permisos::ve_todo() ? '1=1' : $wpdb->prepare( 'p.post_author = %d', get_current_user_id() );
		if ( isset( self::ESTADOS[ $estado ] ) ) {
			$w .= $wpdb->prepare( ' AND a.estado = %s', $estado );
		}
		return $wpdb->get_results( 'SELECT a.*, c.nombre AS arrendatario, b.nombre AS beneficiario FROM ' . self::tabla() . " a JOIN {$wpdb->posts} p ON p.ID = a.propiedad_id LEFT JOIN " . Inmo_Contactos::tabla() . ' c ON c.id = a.arrendatario_id LEFT JOIN ' . Inmo_Contactos::tabla() . " b ON b.id = a.beneficiario_id WHERE {$w} ORDER BY a.id DESC" ); // phpcs:ignore
	}

	public static function terminar( $id, $fecha, $garantia_estado ) {
		global $wpdb;
		$a = self::get( $id );
		if ( ! $a || 'vigente' !== $a->estado ) {
			return new WP_Error( 'inmo_arriendo', 'El arriendo no está vigente.' );
		}
		$fecha = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $fecha ) ? $fecha : current_time( 'Y-m-d' );
		$wpdb->update( self::tabla(), array( 'estado' => 'terminado', 'fecha_termino' => $fecha, 'garantia_estado' => in_array( $garantia_estado, array( 'recibida', 'devuelta', 'aplicada' ), true ) ? $garantia_estado : $a->garantia_estado, 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $id ) );
		Inmo_Historial::registrar( 'administracion', 'Término del arriendo el ' . Inmo_Util::fecha( $fecha ) . '; garantía: ' . $garantia_estado, array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $a->arrendatario_id ) );
		return true;
	}

	/* =====================================================================
	 * Reajustes
	 * =================================================================== */

	/** Reajuste pendiente: llegó la fecha y aún no se aplica. */
	public static function reajuste_pendiente( $a, $fecha = null ) {
		$fecha = $fecha ? $fecha : current_time( 'Y-m-d' );
		return 'vigente' === $a->estado && $a->proximo_reajuste && $a->proximo_reajuste <= $fecha;
	}

	/**
	 * Aplica un reajuste por IPC (porcentaje acumulado del período, informado por el INE).
	 *
	 * @return true|WP_Error
	 */
	public static function aplicar_reajuste( $id, $pct, $fuente = 'INE' ) {
		global $wpdb;
		$a   = self::get( $id );
		$pct = Inmo_Util::parse_numero( $pct );
		if ( ! $a || ! isset( self::REAJUSTE_MESES[ $a->reajuste ] ) ) {
			return new WP_Error( 'inmo_reajuste', 'Este arriendo no se reajusta por IPC.' );
		}
		if ( '' === $pct || $pct < -20 || $pct > 50 ) {
			return new WP_Error( 'inmo_reajuste', 'Indica la variación del IPC en porcentaje (por ejemplo 2,1).' );
		}
		$antes = (float) $a->renta;
		$nueva = 'CLP' === $a->moneda ? round( $antes * ( 1 + $pct / 100 ) ) : round( $antes * ( 1 + $pct / 100 ), 2 );
		$prox  = gmdate( 'Y-m-d', strtotime( ( $a->proximo_reajuste ? $a->proximo_reajuste : current_time( 'Y-m-d' ) ) . ' +' . self::REAJUSTE_MESES[ $a->reajuste ] . ' months' ) );
		$wpdb->update( self::tabla(), array( 'renta' => $nueva, 'proximo_reajuste' => $prox, 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $id ) );
		Inmo_Historial::registrar( 'reajuste_renta', sprintf( 'IPC %s%% (%s): %s → %s. Próximo reajuste: %s', number_format( $pct, 2, ',', '.' ), $fuente, Inmo_Util::formatear_monto( $antes, $a->moneda ), Inmo_Util::formatear_monto( $nueva, $a->moneda ), Inmo_Util::fecha( $prox ) ), array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $a->arrendatario_id ) );
		return true;
	}

	/* =====================================================================
	 * Cobros
	 * =================================================================== */

	public static function vencimiento( $periodo, $dia ) {
		$ultimo = (int) gmdate( 't', strtotime( $periodo . '-01' ) );
		return $periodo . '-' . str_pad( (string) min( (int) $dia, $ultimo ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Genera el cobro de un período (idempotente: un cobro por arriendo y mes).
	 *
	 * @return int|WP_Error|null ID del cobro, null si el arriendo no corresponde a ese período.
	 */
	public static function generar_cobro( $arriendo_id, $periodo ) {
		global $wpdb;
		$a = self::get( $arriendo_id );
		if ( ! $a || ! preg_match( '/^\d{4}-\d{2}$/', $periodo ) ) {
			return new WP_Error( 'inmo_cobro', 'Datos no válidos.' );
		}
		$existe = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'cobros' ) . ' WHERE arriendo_id = %d AND periodo = %s', $arriendo_id, $periodo ) ); // phpcs:ignore
		if ( $existe ) {
			return (int) $existe;
		}
		$ini_p = $periodo . '-01';
		$fin_p = gmdate( 'Y-m-t', strtotime( $ini_p ) );
		if ( 'vigente' !== $a->estado || $a->fecha_inicio > $fin_p || ( $a->fecha_termino && $a->fecha_termino < $ini_p ) ) {
			return null;
		}
		$vence = self::vencimiento( $periodo, $a->dia_pago );
		$renta = (float) $a->renta;
		$nota  = array();
		// Prorrateo del primer y último mes.
		$dias_mes = (int) gmdate( 't', strtotime( $ini_p ) );
		$desde    = max( $ini_p, $a->fecha_inicio );
		$hasta    = $a->fecha_termino ? min( $fin_p, $a->fecha_termino ) : $fin_p;
		$dias     = (int) ( ( strtotime( $hasta ) - strtotime( $desde ) ) / DAY_IN_SECONDS ) + 1;
		if ( $dias < $dias_mes ) {
			$renta  = $renta * $dias / $dias_mes;
			$nota[] = "Proporcional: {$dias} de {$dias_mes} días";
		}
		$uf = null;
		if ( 'UF' === $a->moneda ) {
			// UF del día de vencimiento (la CMF la publica hasta el día 9 del mes siguiente); si aún no se conoce, la última disponible.
			$uf      = Inmo_Indicadores::uf_para( $vence );
			$monto   = (int) round( $renta * $uf['valor'] );
			$nota[]  = 'UF ' . Inmo_Util::formatear_monto( $uf['valor'], 'CLP' ) . ' al ' . Inmo_Util::fecha( $uf['fecha'] ) . ( $uf['exacta'] ? '' : ' (última UF conocida; vence el ' . Inmo_Util::fecha( $vence ) . ')' ) . ' (' . $uf['fuente'] . ')';
		} else {
			$monto = (int) round( $renta );
		}
		if ( self::reajuste_pendiente( $a, $vence ) ) {
			$nota[] = 'Reajuste pendiente de aplicar';
		}
		$wpdb->insert(
			self::t( 'cobros' ),
			array(
				'arriendo_id' => $arriendo_id,
				'periodo'     => $periodo,
				'vence'       => $vence,
				'renta_base'  => round( $renta, 2 ),
				'moneda_base' => $a->moneda,
				'uf_valor'    => $uf ? $uf['valor'] : null,
				'uf_fecha'    => $uf ? $uf['fecha'] : null,
				'monto_renta' => $monto,
				'cargos'      => '',
				'total'       => $monto,
				'pagado'      => 0,
				'estado'      => 'pendiente',
				'nota'        => implode( '. ', $nota ),
				'creado'      => Inmo_Util::ahora(),
				'actualizado' => Inmo_Util::ahora(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			self::recalcular_estado( $id );
			Inmo_Historial::registrar( 'cobro', 'Cobro ' . self::periodo_texto( $periodo ) . ': ' . Inmo_Util::formatear_monto( $monto, 'CLP' ) . ', vence el ' . Inmo_Util::fecha( $vence ), array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $a->arrendatario_id ) );
			self::aviso_cobro( $id );
		}
		return $id;
	}

	/** Genera los cobros del período para todos los arriendos vigentes visibles. @return array [creados, existentes] */
	public static function generar_periodo( $periodo, $solo_visibles = true ) {
		global $wpdb;
		$antes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'cobros' ) . ' WHERE periodo = %s', $periodo ) ); // phpcs:ignore
		$arrs  = $solo_visibles ? self::listar( 'vigente' ) : $wpdb->get_results( 'SELECT * FROM ' . self::tabla() . " WHERE estado = 'vigente'" ); // phpcs:ignore
		foreach ( $arrs as $a ) {
			self::generar_cobro( $a->id, $periodo );
		}
		$despues = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'cobros' ) . ' WHERE periodo = %s', $periodo ) ); // phpcs:ignore
		return array( $despues - $antes, $antes );
	}

	public static function cobro( $id ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'cobros' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( $c ) {
			$c->cargos = $c->cargos ? (array) json_decode( $c->cargos, true ) : array();
		}
		return $c;
	}

	public static function cobros_de( $arriendo_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'cobros' ) . ' WHERE arriendo_id = %d ORDER BY periodo DESC', $arriendo_id ) ); // phpcs:ignore
	}

	/** Agrega un cargo adicional al cobro (p. ej. gastos comunes cobrados por la corredora, reparación imputable al arrendatario). */
	public static function agregar_cargo( $cobro_id, $concepto, $monto ) {
		global $wpdb;
		$c     = self::cobro( $cobro_id );
		$monto = (int) round( (float) Inmo_Util::parse_numero( $monto ) );
		if ( ! $c || in_array( $c->estado, array( 'pagado', 'anulado' ), true ) ) {
			return new WP_Error( 'inmo_cargo', 'El cobro ya no admite cargos.' );
		}
		if ( ! $monto || '' === trim( $concepto ) ) {
			return new WP_Error( 'inmo_cargo', 'Indica concepto y monto.' );
		}
		$c->cargos[] = array( 'concepto' => sanitize_text_field( $concepto ), 'monto' => $monto );
		$total       = (int) $c->monto_renta + array_sum( wp_list_pluck( $c->cargos, 'monto' ) );
		$wpdb->update( self::t( 'cobros' ), array( 'cargos' => wp_json_encode( $c->cargos ), 'total' => $total, 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $cobro_id ) );
		self::recalcular_estado( $cobro_id );
		return true;
	}

	/**
	 * Registra un pago (total o parcial) del arrendatario.
	 *
	 * @return int|WP_Error
	 */
	public static function registrar_pago( $cobro_id, $monto, $fecha, $medio, $referencia = '', $archivo = null ) {
		global $wpdb;
		$c     = self::cobro( $cobro_id );
		$monto = (int) round( (float) Inmo_Util::parse_numero( $monto ) );
		if ( ! $c || 'anulado' === $c->estado ) {
			return new WP_Error( 'inmo_pago', 'Cobro no válido.' );
		}
		if ( $monto <= 0 ) {
			return new WP_Error( 'inmo_pago', 'Indica el monto pagado.' );
		}
		if ( $c->pagado + $monto > $c->total ) {
			return new WP_Error( 'inmo_pago', 'El pago supera el saldo pendiente (' . Inmo_Util::formatear_monto( $c->total - $c->pagado, 'CLP' ) . ').' );
		}
		$a   = self::get( $c->arriendo_id );
		$doc = 0;
		if ( $archivo && ! empty( $archivo['name'] ) ) {
			$doc = Inmo_Documentos::guardar_subida( $archivo, $a->propiedad_id, 'otro', $a->arrendatario_id, false, 'comprobante_pago' );
			if ( is_wp_error( $doc ) ) {
				return $doc;
			}
		}
		$wpdb->insert(
			self::t( 'pagos' ),
			array(
				'cobro_id'     => $cobro_id,
				'monto'        => $monto,
				'fecha'        => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $fecha ) ? $fecha : current_time( 'Y-m-d' ),
				'medio'        => isset( self::MEDIOS[ $medio ] ) ? $medio : 'otro',
				'referencia'   => sanitize_text_field( $referencia ),
				'documento_id' => (int) $doc,
				'user_id'      => get_current_user_id(),
				'creado'       => Inmo_Util::ahora(),
			)
		);
		$pid = (int) $wpdb->insert_id;
		$wpdb->update( self::t( 'cobros' ), array( 'pagado' => $c->pagado + $monto, 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $cobro_id ) );
		$estado = self::recalcular_estado( $cobro_id );
		Inmo_Historial::registrar( 'pago', 'Pago ' . self::periodo_texto( $c->periodo ) . ': ' . Inmo_Util::formatear_monto( $monto, 'CLP' ) . ' (' . self::MEDIOS[ isset( self::MEDIOS[ $medio ] ) ? $medio : 'otro' ] . ') → ' . self::ESTADOS_COBRO[ $estado ], array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $a->arrendatario_id ) );
		self::comprobante_pago( $pid );
		return $pid;
	}

	public static function pagos_de( $cobro_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pagos' ) . ' WHERE cobro_id = %d ORDER BY id', $cobro_id ) ); // phpcs:ignore
	}

	/** Estado según saldo y vencimiento. */
	public static function recalcular_estado( $cobro_id ) {
		global $wpdb;
		$c = self::cobro( $cobro_id );
		if ( ! $c || 'anulado' === $c->estado ) {
			return $c ? $c->estado : '';
		}
		if ( $c->pagado >= $c->total ) {
			$e = 'pagado';
		} elseif ( $c->vence < current_time( 'Y-m-d' ) ) {
			$e = 'vencido';
		} elseif ( $c->pagado > 0 ) {
			$e = 'parcial';
		} else {
			$e = 'pendiente';
		}
		if ( $e !== $c->estado ) {
			$wpdb->update( self::t( 'cobros' ), array( 'estado' => $e, 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $cobro_id ) );
		}
		return $e;
	}

	public static function anular_cobro( $cobro_id, $motivo ) {
		global $wpdb;
		$c = self::cobro( $cobro_id );
		if ( ! $c || $c->pagado > 0 ) {
			return new WP_Error( 'inmo_cobro', 'Solo se anulan cobros sin pagos.' );
		}
		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'inmo_cobro', 'Indica el motivo.' );
		}
		$wpdb->update( self::t( 'cobros' ), array( 'estado' => 'anulado', 'nota' => trim( $c->nota . '. Anulado: ' . sanitize_text_field( $motivo ), '. ' ), 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $cobro_id ) );
		return true;
	}

	/** Cobros con saldo y vencidos (morosidad), visibles para el usuario. */
	public static function morosos() {
		global $wpdb;
		$w = Inmo_Permisos::ve_todo() ? '1=1' : $wpdb->prepare( 'p.post_author = %d', get_current_user_id() );
		return $wpdb->get_results( 'SELECT k.*, a.propiedad_id, a.arrendatario_id, c.nombre, c.telefono FROM ' . self::t( 'cobros' ) . ' k JOIN ' . self::tabla() . " a ON a.id = k.arriendo_id JOIN {$wpdb->posts} p ON p.ID = a.propiedad_id LEFT JOIN " . Inmo_Contactos::tabla() . " c ON c.id = a.arrendatario_id WHERE k.estado = 'vencido' AND {$w} ORDER BY k.vence" ); // phpcs:ignore
	}

	public static function cobros_periodo( $periodo, $estado = '' ) {
		global $wpdb;
		$w = Inmo_Permisos::ve_todo() ? '1=1' : $wpdb->prepare( 'p.post_author = %d', get_current_user_id() );
		if ( $periodo ) {
			$w .= $wpdb->prepare( ' AND k.periodo = %s', $periodo );
		}
		if ( isset( self::ESTADOS_COBRO[ $estado ] ) ) {
			$w .= $wpdb->prepare( ' AND k.estado = %s', $estado );
		}
		return $wpdb->get_results( 'SELECT k.*, a.propiedad_id, a.arrendatario_id, c.nombre FROM ' . self::t( 'cobros' ) . ' k JOIN ' . self::tabla() . " a ON a.id = k.arriendo_id JOIN {$wpdb->posts} p ON p.ID = a.propiedad_id LEFT JOIN " . Inmo_Contactos::tabla() . " c ON c.id = a.arrendatario_id WHERE {$w} ORDER BY k.vence, k.id" ); // phpcs:ignore
	}

	/* =====================================================================
	 * Gastos y abonos (se imputan en la liquidación al propietario)
	 * =================================================================== */

	public static function registrar_movimiento( $arriendo_id, $fecha, $concepto, $monto, $tipo = 'gasto', $archivo = null ) {
		global $wpdb;
		$a     = self::get( $arriendo_id );
		$monto = (int) round( (float) Inmo_Util::parse_numero( $monto ) );
		if ( ! $a || $monto <= 0 || '' === trim( $concepto ) ) {
			return new WP_Error( 'inmo_mov', 'Indica fecha, concepto y monto.' );
		}
		$doc = 0;
		if ( $archivo && ! empty( $archivo['name'] ) ) {
			$doc = Inmo_Documentos::guardar_subida( $archivo, $a->propiedad_id, 'otro', $a->beneficiario_id, false, 'respaldo_gasto' );
			if ( is_wp_error( $doc ) ) {
				return $doc;
			}
		}
		$wpdb->insert(
			self::t( 'movimientos' ),
			array(
				'arriendo_id'  => $arriendo_id,
				'fecha'        => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $fecha ) ? $fecha : current_time( 'Y-m-d' ),
				'concepto'     => sanitize_text_field( $concepto ),
				'monto'        => 'abono' === $tipo ? -$monto : $monto,
				'a_cargo'      => 'propietario',
				'documento_id' => (int) $doc,
				'user_id'      => get_current_user_id(),
				'creado'       => Inmo_Util::ahora(),
			)
		);
		Inmo_Historial::registrar( 'gasto', ( 'abono' === $tipo ? 'Abono' : 'Gasto' ) . ' a cuenta del propietario: ' . $concepto . ' ' . Inmo_Util::formatear_monto( $monto, 'CLP' ), array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $a->beneficiario_id ) );
		return (int) $wpdb->insert_id;
	}

	public static function movimientos_de( $arriendo_id, $pendientes = false ) {
		global $wpdb;
		$extra = $pendientes ? ' AND liquidacion_id = 0' : '';
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'movimientos' ) . " WHERE arriendo_id = %d {$extra} ORDER BY fecha, id", $arriendo_id ) ); // phpcs:ignore
	}

	/* =====================================================================
	 * Tarea diaria, avisos y formatos
	 * =================================================================== */

	public static function tarea_diaria() {
		global $wpdb;
		$hoy = current_time( 'Y-m-d' );
		if ( (int) current_time( 'j' ) >= (int) Inmo_Ajustes::get( 'cobro_dia_generacion', 1 ) ) {
			self::generar_periodo( current_time( 'Y-m' ), false );
		}
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'cobros' ) . " WHERE estado IN ('pendiente','parcial') AND vence < %s", $hoy ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			self::recalcular_estado( $id );
		}
	}

	public static function periodo_texto( $periodo ) {
		$meses = array( 1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		list( $y, $m ) = array_map( 'intval', explode( '-', $periodo ) );
		return $meses[ $m ] . ' ' . $y;
	}

	private static function aviso_cobro( $cobro_id ) {
		$c = self::cobro( $cobro_id );
		$a = self::get( $c->arriendo_id );
		$p = Inmo_Contactos::get( $a->arrendatario_id );
		if ( ! $p || ! $p->email ) {
			return;
		}
		Inmo_Correo::enviar_simple(
			$p->email,
			'Cobro de arriendo ' . self::periodo_texto( $c->periodo ) . ' · ' . get_post_meta( $a->propiedad_id, '_inmo_codigo', true ),
			"Hola {$p->nombre}:\n\nEl arriendo de " . self::periodo_texto( $c->periodo ) . ' es de ' . Inmo_Util::formatear_monto( $c->total, 'CLP' ) . ' y vence el ' . Inmo_Util::fecha( $c->vence ) . '.' . ( $c->nota ? "\n" . $c->nota . '.' : '' ) . "\n\nDatos para el pago:\n" . Inmo_Ajustes::get( 'datos_pago' ),
			array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $p->id )
		);
	}

	private static function comprobante_pago( $pago_id ) {
		global $wpdb;
		$pg = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'pagos' ) . ' WHERE id = %d', $pago_id ) ); // phpcs:ignore
		$c  = self::cobro( $pg->cobro_id );
		$a  = self::get( $c->arriendo_id );
		$p  = Inmo_Contactos::get( $a->arrendatario_id );
		if ( ! $p || ! $p->email ) {
			return;
		}
		$saldo = $c->total - $c->pagado;
		Inmo_Correo::enviar_simple(
			$p->email,
			'Recibimos tu pago · arriendo ' . self::periodo_texto( $c->periodo ),
			"Hola {$p->nombre}:\n\nRecibimos " . Inmo_Util::formatear_monto( $pg->monto, 'CLP' ) . ' el ' . Inmo_Util::fecha( $pg->fecha ) . ' por el arriendo de ' . self::periodo_texto( $c->periodo ) . '.' . ( $saldo > 0 ? ' Saldo pendiente: ' . Inmo_Util::formatear_monto( $saldo, 'CLP' ) . '.' : ' El mes quedó pagado.' ),
			array( 'propiedad_id' => $a->propiedad_id, 'contacto_id' => $p->id )
		);
	}
}
