<?php
/**
 * Oportunidades: el negocio entre un contacto y (opcionalmente) una propiedad, desde el primer contacto
 * hasta el cierre ganado o perdido. Agrupa consultas, visitas, ofertas, notas, condiciones y contratos.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Oportunidades {

	const ETAPAS = array(
		'nuevo'       => 'Primer contacto',
		'calificado'  => 'Calificado',
		'visita'      => 'Visita',
		'negociacion' => 'Oferta / negociación',
		'reserva'     => 'Reserva',
		'contrato'    => 'Contrato / promesa',
		'ganada'      => 'Cierre ganado',
		'perdida'     => 'Cierre perdido',
	);

	const ABIERTAS = array( 'nuevo', 'calificado', 'visita', 'negociacion', 'reserva', 'contrato' );

	/** Condiciones editables de la operación (alimentan los contratos). */
	public static function campos_condiciones( $operacion ) {
		$comun = array(
			'precio_acordado' => array( 'label' => 'arriendo' === $operacion ? 'Renta acordada' : 'Precio acordado', 'tipo' => 'numero', 'ayuda' => 'Vacío = precio publicado' ),
			'moneda_acordada' => array( 'label' => 'Moneda', 'tipo' => 'select', 'opciones' => array( '' => 'La de la publicación', 'UF' => 'UF', 'CLP' => 'Pesos' ) ),
			'reserva_monto'   => array( 'label' => 'Monto de reserva', 'tipo' => 'numero' ),
			'reserva_moneda'  => array( 'label' => 'Moneda de la reserva', 'tipo' => 'select', 'opciones' => array( 'CLP' => 'Pesos', 'UF' => 'UF' ) ),
			'reserva_fecha'   => array( 'label' => 'Fecha de reserva', 'tipo' => 'fecha' ),
		);
		if ( 'arriendo' === $operacion ) {
			return array_merge(
				$comun,
				array(
					'fecha_inicio'   => array( 'label' => 'Inicio del arriendo', 'tipo' => 'fecha' ),
					'plazo_meses'    => array( 'label' => 'Plazo (meses)', 'tipo' => 'numero', 'ayuda' => 'Vacío = plazo de la publicación' ),
					'dia_pago'       => array( 'label' => 'Día de pago (1–31)', 'tipo' => 'numero' ),
					'garantia_meses' => array( 'label' => 'Garantía (meses)', 'tipo' => 'numero', 'ayuda' => 'Vacío = la de la publicación' ),
					'aval_nombre'    => array( 'label' => 'Aval / codeudor (nombre)', 'tipo' => 'texto' ),
					'aval_rut'       => array( 'label' => 'RUT del aval', 'tipo' => 'texto' ),
				)
			);
		}
		return array_merge(
			$comun,
			array(
				'pie'             => array( 'label' => 'Pie', 'tipo' => 'numero' ),
				'credito'         => array( 'label' => 'Crédito hipotecario', 'tipo' => 'select', 'opciones' => array( '' => '—', 'si' => 'Sí', 'no' => 'No (contado)' ) ),
				'fecha_promesa'   => array( 'label' => 'Fecha de promesa', 'tipo' => 'fecha' ),
				'fecha_escritura' => array( 'label' => 'Fecha prevista de escritura', 'tipo' => 'fecha' ),
				'notaria'         => array( 'label' => 'Notaría', 'tipo' => 'texto' ),
			)
		);
	}

	public static function init() {
		add_action( 'inmo_estado_cambiado', array( __CLASS__, 'al_cambiar_estado_propiedad' ), 10, 3 );
	}

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_oportunidades';
	}

	public static function get( $id ) {
		global $wpdb;
		$o = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( $o ) {
			$o->condiciones = $o->condiciones ? (array) json_decode( $o->condiciones, true ) : array();
		}
		return $o;
	}

	public static function crear( array $d ) {
		global $wpdb;
		$prop = ! empty( $d['propiedad_id'] ) ? (int) $d['propiedad_id'] : 0;
		$op   = ! empty( $d['operacion'] ) ? $d['operacion'] : ( $prop ? Inmo_Propiedades::termino( $prop, Inmo_Propiedades::TAX_OPERACION ) : '' );
		$corr = ! empty( $d['corredor_id'] ) ? (int) $d['corredor_id'] : ( $prop ? (int) get_post_field( 'post_author', $prop ) : Inmo_Ajustes::corredor_defecto() );
		$wpdb->insert(
			self::tabla(),
			array(
				'contacto_id'  => (int) $d['contacto_id'],
				'propiedad_id' => $prop,
				'operacion'    => isset( Inmo_Esquema::OPERACIONES[ $op ] ) ? $op : '',
				'etapa'        => isset( $d['etapa'], self::ETAPAS[ $d['etapa'] ] ) ? $d['etapa'] : 'nuevo',
				'corredor_id'  => $corr,
				'valor'        => $prop && '' !== get_post_meta( $prop, '_inmo_precio', true ) ? (float) get_post_meta( $prop, '_inmo_precio', true ) : null,
				'moneda'       => $prop ? (string) get_post_meta( $prop, '_inmo_moneda', true ) : '',
				'condiciones'  => '',
				'origen'       => isset( $d['origen'] ) ? sanitize_key( $d['origen'] ) : 'web',
				'creado'       => Inmo_Util::ahora(),
				'actualizado'  => Inmo_Util::ahora(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			Inmo_Historial::registrar( 'oportunidad', 'Oportunidad creada' . ( $prop ? ' por ' . get_post_meta( $prop, '_inmo_codigo', true ) : ' (búsqueda general)' ), array( 'propiedad_id' => $prop, 'contacto_id' => (int) $d['contacto_id'], 'oportunidad_id' => $id ) );
		}
		return $id;
	}

	/** Oportunidad abierta del contacto para esa propiedad (o general). La crea si no existe. */
	public static function para_consulta( $contacto_id, $propiedad_id, $tipo, $corredor_id = 0 ) {
		global $wpdb;
		$in = "'" . implode( "','", self::ABIERTAS ) . "'";
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::tabla() . " WHERE contacto_id = %d AND propiedad_id = %d AND etapa IN ({$in}) ORDER BY id DESC LIMIT 1", $contacto_id, $propiedad_id ) ); // phpcs:ignore
		if ( ! $id ) {
			$id = self::crear( array( 'contacto_id' => $contacto_id, 'propiedad_id' => $propiedad_id, 'corredor_id' => $corredor_id ) );
		}
		if ( 'visita' === $tipo ) {
			self::avanzar_a( $id, 'visita' );
		}
		return $id;
	}

	/** Avanza a una etapa solo si la oportunidad está en una etapa anterior. */
	public static function avanzar_a( $id, $etapa ) {
		$o = self::get( $id );
		if ( ! $o || ! in_array( $o->etapa, self::ABIERTAS, true ) ) {
			return;
		}
		$orden = array_keys( self::ETAPAS );
		if ( array_search( $o->etapa, $orden, true ) < array_search( $etapa, $orden, true ) ) {
			self::cambiar_etapa( $id, $etapa );
		}
	}

	/**
	 * @return true|WP_Error
	 */
	public static function cambiar_etapa( $id, $etapa, $motivo = '' ) {
		global $wpdb;
		$o = self::get( $id );
		if ( ! $o || ! isset( self::ETAPAS[ $etapa ] ) ) {
			return new WP_Error( 'inmo_etapa', 'Etapa no válida.' );
		}
		if ( 'perdida' === $etapa && '' === trim( $motivo ) ) {
			return new WP_Error( 'inmo_motivo', 'Indica el motivo de la pérdida.' );
		}
		if ( $o->etapa === $etapa ) {
			return true;
		}
		$upd = array( 'etapa' => $etapa, 'actualizado' => Inmo_Util::ahora() );
		if ( in_array( $etapa, array( 'ganada', 'perdida' ), true ) ) {
			$upd['cerrado'] = Inmo_Util::ahora();
		} else {
			$upd['cerrado'] = null;
		}
		if ( 'perdida' === $etapa ) {
			$upd['motivo_perdida'] = sanitize_text_field( $motivo );
		}
		$wpdb->update( self::tabla(), $upd, array( 'id' => $id ) );
		Inmo_Historial::registrar( 'etapa', self::ETAPAS[ $o->etapa ] . ' → ' . self::ETAPAS[ $etapa ] . ( $motivo ? ' (' . $motivo . ')' : '' ), array( 'propiedad_id' => $o->propiedad_id, 'contacto_id' => $o->contacto_id, 'oportunidad_id' => $id ) );
		return true;
	}

	public static function actualizar( $id, array $d ) {
		global $wpdb;
		$o = self::get( $id );
		if ( ! $o ) {
			return;
		}
		$upd = array( 'actualizado' => Inmo_Util::ahora() );
		if ( isset( $d['condiciones'] ) ) {
			$limpio = array();
			foreach ( self::campos_condiciones( $o->operacion ) as $k => $c ) {
				if ( ! isset( $d['condiciones'][ $k ] ) ) {
					continue;
				}
				$v = $d['condiciones'][ $k ];
				if ( 'numero' === $c['tipo'] ) {
					$v = Inmo_Util::parse_numero( $v );
				} elseif ( 'fecha' === $c['tipo'] ) {
					$v = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? $v : '';
				} elseif ( 'select' === $c['tipo'] ) {
					$v = isset( $c['opciones'][ $v ] ) ? $v : '';
				} else {
					$v = sanitize_text_field( $v );
				}
				if ( 'aval_rut' === $k && $v ) {
					$v = Inmo_Util::normalizar_rut( $v );
				}
				if ( '' !== $v ) {
					$limpio[ $k ] = $v;
				}
			}
			$upd['condiciones'] = wp_json_encode( $limpio );
		}
		if ( isset( $d['proxima_accion'] ) ) {
			$upd['proxima_accion'] = sanitize_text_field( $d['proxima_accion'] );
		}
		if ( array_key_exists( 'proxima_fecha', $d ) ) {
			$upd['proxima_fecha'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d['proxima_fecha'] ) ? $d['proxima_fecha'] : null;
		}
		if ( ! empty( $d['corredor_id'] ) && Inmo_Permisos::ve_todo() ) {
			$upd['corredor_id'] = (int) $d['corredor_id'];
		}
		$wpdb->update( self::tabla(), $upd, array( 'id' => $id ) );
	}

	/** Registrar reserva: la propiedad pasa a Reservada y la oportunidad a la etapa Reserva. */
	public static function reservar( $id, $monto, $moneda, $fecha ) {
		$o = self::get( $id );
		if ( ! $o || ! $o->propiedad_id ) {
			return new WP_Error( 'inmo_reserva', 'La reserva requiere una propiedad.' );
		}
		$monto = Inmo_Util::parse_numero( $monto );
		if ( '' === $monto || $monto <= 0 ) {
			return new WP_Error( 'inmo_reserva', 'Indica el monto de la reserva.' );
		}
		$cond                   = $o->condiciones;
		$cond['reserva_monto']  = $monto;
		$cond['reserva_moneda'] = 'UF' === $moneda ? 'UF' : 'CLP';
		$cond['reserva_fecha']  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $fecha ) ? $fecha : current_time( 'Y-m-d' );
		self::actualizar( $id, array( 'condiciones' => $cond ) );
		$r = Inmo_Propiedades::cambiar_estado( $o->propiedad_id, 'reservada' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::cambiar_etapa( $id, 'reserva' );
		Inmo_Historial::registrar( 'reserva', 'Reserva por ' . Inmo_Util::formatear_monto( $monto, $cond['reserva_moneda'] ), array( 'propiedad_id' => $o->propiedad_id, 'contacto_id' => $o->contacto_id, 'oportunidad_id' => $id ) );
		return true;
	}

	/**
	 * Cierre ganado: la propiedad pasa a Vendida/Arrendada; opcionalmente se cierran como perdidas
	 * las demás oportunidades abiertas de la misma propiedad.
	 */
	public static function cerrar_ganada( $id, $cerrar_otras = true ) {
		$o = self::get( $id );
		if ( ! $o ) {
			return new WP_Error( 'inmo_cierre', 'Oportunidad no encontrada.' );
		}
		if ( $o->propiedad_id ) {
			$estado = 'arriendo' === $o->operacion ? 'arrendada' : 'vendida';
			$r      = Inmo_Propiedades::cambiar_estado( $o->propiedad_id, $estado );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		self::cambiar_etapa( $id, 'ganada' );
		Inmo_Historial::registrar( 'cierre', 'Cierre ganado', array( 'propiedad_id' => $o->propiedad_id, 'contacto_id' => $o->contacto_id, 'oportunidad_id' => $id ) );
		if ( $cerrar_otras && $o->propiedad_id ) {
			foreach ( self::de_propiedad( $o->propiedad_id, true ) as $otra ) {
				if ( (int) $otra->id !== (int) $id ) {
					self::cambiar_etapa( $otra->id, 'perdida', 'La propiedad se cerró con otro cliente' );
				}
			}
		}
		return true;
	}

	/** Si la propiedad se vende/arrienda desde su ficha, se informa en el historial (no se cierran oportunidades automáticamente). */
	public static function al_cambiar_estado_propiedad( $post_id, $nuevo, $anterior ) {
		if ( 'disponible' === $nuevo && 'reservada' === $anterior ) {
			foreach ( self::de_propiedad( $post_id, true ) as $o ) {
				if ( 'reserva' === $o->etapa ) {
					Inmo_Historial::registrar( 'reserva', 'La propiedad volvió a Disponible: revisar la reserva', array( 'propiedad_id' => $post_id, 'oportunidad_id' => $o->id ) );
				}
			}
		}
	}

	public static function de_propiedad( $propiedad_id, $solo_abiertas = false ) {
		global $wpdb;
		$extra = $solo_abiertas ? " AND o.etapa IN ('" . implode( "','", self::ABIERTAS ) . "')" : '';
		return $wpdb->get_results( $wpdb->prepare( 'SELECT o.*, c.nombre FROM ' . self::tabla() . ' o LEFT JOIN ' . Inmo_Contactos::tabla() . " c ON c.id = o.contacto_id WHERE o.propiedad_id = %d {$extra} ORDER BY o.id DESC", $propiedad_id ) ); // phpcs:ignore
	}

	public static function de_contacto( $contacto_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE contacto_id = %d ORDER BY id DESC', $contacto_id ) ); // phpcs:ignore
	}

	/** Condición SQL de oportunidades visibles. */
	public static function sql_visibles( $alias = 'o' ) {
		global $wpdb;
		if ( Inmo_Permisos::ve_todo() ) {
			return '1=1';
		}
		$u = get_current_user_id();
		return $wpdb->prepare( "( {$alias}.corredor_id = %d OR EXISTS ( SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = {$alias}.propiedad_id AND p.post_author = %d ) )", $u, $u );
	}

	public static function puede_ver( $o ) {
		if ( ! $o || ! current_user_can( 'inmo_gestionar' ) ) {
			return false;
		}
		if ( Inmo_Permisos::ve_todo() || (int) $o->corredor_id === get_current_user_id() ) {
			return true;
		}
		return $o->propiedad_id && Inmo_Permisos::puede_ver_privado( $o->propiedad_id );
	}

	public static function listar( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args( $args, array( 'operacion' => '', 'corredor_id' => 0, 'incluir_cerradas' => true ) );
		$where = array( self::sql_visibles( 'o' ) );
		if ( isset( Inmo_Esquema::OPERACIONES[ $args['operacion'] ] ) ) {
			$where[] = $wpdb->prepare( 'o.operacion = %s', $args['operacion'] );
		}
		if ( $args['corredor_id'] ) {
			$where[] = $wpdb->prepare( 'o.corredor_id = %d', $args['corredor_id'] );
		}
		if ( ! $args['incluir_cerradas'] ) {
			$where[] = "o.etapa IN ('" . implode( "','", self::ABIERTAS ) . "')";
		} else {
			// Cerradas: solo las de los últimos 90 días, para que el tablero no crezca sin límite.
			$where[] = $wpdb->prepare( "( o.cerrado IS NULL OR o.cerrado >= %s )", gmdate( 'Y-m-d', strtotime( Inmo_Util::ahora() . ' -90 days' ) ) );
		}
		$w = implode( ' AND ', $where );
		return $wpdb->get_results( 'SELECT o.*, c.nombre, c.telefono FROM ' . self::tabla() . ' o LEFT JOIN ' . Inmo_Contactos::tabla() . " c ON c.id = o.contacto_id WHERE {$w} ORDER BY o.actualizado DESC LIMIT 500" ); // phpcs:ignore
	}

	/** Valor de referencia de la oportunidad (precio acordado o publicado). */
	public static function valor_texto( $o ) {
		$cond = is_array( $o->condiciones ) ? $o->condiciones : (array) json_decode( (string) $o->condiciones, true );
		if ( ! empty( $cond['precio_acordado'] ) ) {
			$mon = ! empty( $cond['moneda_acordada'] ) ? $cond['moneda_acordada'] : $o->moneda;
			return Inmo_Util::formatear_monto( $cond['precio_acordado'], $mon );
		}
		return null !== $o->valor && '' !== $o->valor ? Inmo_Util::formatear_monto( $o->valor, $o->moneda ) : '';
	}
}
