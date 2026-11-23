<?php
/**
 * CRM: consultas, solicitudes de visita, captaciones y solicitudes de valorización.
 * Cada registro se vincula a un contacto y, cuando corresponde, a una propiedad.
 * Las consultas generales quedan con propiedad_id = 0 (sin asociación artificial).
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Consultas {

	const TIPOS = array(
		'consulta'     => 'Consulta',
		'visita'       => 'Solicitud de visita',
		'captacion'    => 'Captación (propietario)',
		'valorizacion' => 'Valorización',
		'general'      => 'Consulta general',
	);

	const ESTADOS_CONSULTA = array(
		'nueva'      => 'Nueva',
		'en_gestion' => 'En gestión',
		'atendida'   => 'Atendida',
		'descartada' => 'Descartada',
	);

	const ESTADOS_VISITA = array(
		'pendiente'    => 'Pendiente de confirmación',
		'confirmada'   => 'Confirmada',
		'reprogramada' => 'Reprogramada',
		'realizada'    => 'Realizada',
		'cancelada'    => 'Cancelada',
	);

	const FRANJAS = array(
		'manana'      => 'Mañana (9:00–13:00)',
		'tarde'       => 'Tarde (14:00–19:00)',
		'indiferente' => 'Indiferente',
	);

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_consultas';
	}

	public static function estados_de( $tipo ) {
		return 'visita' === $tipo ? self::ESTADOS_VISITA : self::ESTADOS_CONSULTA;
	}

	public static function etiqueta_estado( $c ) {
		$e = self::estados_de( $c->tipo );
		return isset( $e[ $c->estado ] ) ? $e[ $c->estado ] : $c->estado;
	}

	public static function crear( array $d ) {
		global $wpdb;
		$tipo = isset( self::TIPOS[ $d['tipo'] ] ) ? $d['tipo'] : 'consulta';
		$prop = ! empty( $d['propiedad_id'] ) ? (int) $d['propiedad_id'] : 0;
		$corredor = ! empty( $d['corredor_id'] ) ? (int) $d['corredor_id'] : 0;
		if ( ! $corredor && $prop ) {
			$corredor = (int) get_post_field( 'post_author', $prop );
		}
		if ( ! $corredor ) {
			$corredor = Inmo_Ajustes::corredor_defecto();
		}
		$wpdb->insert(
			self::tabla(),
			array(
				'tipo'           => $tipo,
				'contacto_id'    => (int) $d['contacto_id'],
				'propiedad_id'   => $prop,
				'codigo'         => $prop ? (string) get_post_meta( $prop, '_inmo_codigo', true ) : '',
				'mensaje'        => isset( $d['mensaje'] ) ? sanitize_textarea_field( $d['mensaje'] ) : '',
				'datos'          => ! empty( $d['datos'] ) ? wp_json_encode( $d['datos'] ) : '',
				'estado'         => 'visita' === $tipo ? 'pendiente' : 'nueva',
				'visita_fecha'   => null,
				'corredor_id'    => $corredor,
				'origen'         => isset( $d['origen'] ) ? sanitize_key( $d['origen'] ) : 'web',
				'consentimiento' => ! empty( $d['consentimiento'] ) ? Inmo_Util::ahora() : null,
				'creado'         => Inmo_Util::ahora(),
				'actualizado'    => Inmo_Util::ahora(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id && in_array( $tipo, array( 'consulta', 'visita', 'general' ), true ) ) {
			$op_id = ! empty( $d['oportunidad_id'] ) ? (int) $d['oportunidad_id'] : Inmo_Oportunidades::para_consulta( (int) $d['contacto_id'], $prop, $tipo, $corredor );
			if ( $op_id ) {
				$wpdb->update( self::tabla(), array( 'oportunidad_id' => $op_id ), array( 'id' => $id ) );
			}
		}
		if ( $id ) {
			$acciones = array(
				'visita'       => 'visita_solicitada',
				'captacion'    => 'captacion_web',
				'valorizacion' => 'valorizacion',
			);
			$accion = isset( $acciones[ $tipo ] ) ? $acciones[ $tipo ] : 'consulta_web';
			if ( 'general' === $tipo && ! empty( $d['datos']['busqueda'] ) ) {
				$accion = 'busqueda';
			}
			Inmo_Historial::registrar(
				$accion,
				self::TIPOS[ $tipo ] . ( $prop ? ' por ' . get_post_meta( $prop, '_inmo_codigo', true ) : ' (general)' ),
				array( 'propiedad_id' => $prop, 'contacto_id' => (int) $d['contacto_id'], 'consulta_id' => $id )
			);
		}
		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( $c ) {
			$c->datos = $c->datos ? json_decode( $c->datos, true ) : array();
		}
		return $c;
	}

	/**
	 * Cambia el estado. Para visitas, $fecha (Y-m-d H:i) es obligatoria al confirmar o reprogramar.
	 *
	 * @return true|WP_Error
	 */
	public static function cambiar_estado( $id, $estado, $fecha = '', $nota = '' ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c ) {
			return new WP_Error( 'inmo_no_existe', 'La consulta no existe.' );
		}
		$validos = self::estados_de( $c->tipo );
		if ( ! isset( $validos[ $estado ] ) ) {
			return new WP_Error( 'inmo_estado', 'Estado no válido.' );
		}
		$upd = array( 'estado' => $estado, 'actualizado' => Inmo_Util::ahora() );
		if ( 'visita' === $c->tipo && in_array( $estado, array( 'confirmada', 'reprogramada' ), true ) ) {
			$f = $fecha ? date_create_immutable( str_replace( 'T', ' ', (string) $fecha ), wp_timezone() ) : false;
			if ( ! $f ) {
				return new WP_Error( 'inmo_fecha', 'Indica la fecha y hora de la visita.' );
			}
			$upd['visita_fecha'] = $f->format( 'Y-m-d H:i:s' ); // Hora local del sitio.
		}
		$wpdb->update( self::tabla(), $upd, array( 'id' => $id ) );

		$detalle = $validos[ $estado ];
		if ( ! empty( $upd['visita_fecha'] ) ) {
			$detalle .= ' para el ' . Inmo_Util::fecha( $upd['visita_fecha'], true );
		}
		if ( $nota ) {
			$detalle .= ' — ' . $nota;
		}
		$accion = 'visita' === $c->tipo ? 'visita_' . $estado : 'consulta_estado';
		if ( 'visita_pendiente' === $accion ) {
			$accion = 'consulta_estado';
		}
		Inmo_Historial::registrar( $accion, $detalle, array( 'propiedad_id' => $c->propiedad_id, 'contacto_id' => $c->contacto_id, 'consulta_id' => $c->id ) );

		if ( 'visita' === $c->tipo && $c->oportunidad_id && in_array( $estado, array( 'confirmada', 'realizada' ), true ) ) {
			Inmo_Oportunidades::avanzar_a( $c->oportunidad_id, 'visita' );
		}
		if ( 'visita' === $c->tipo && in_array( $estado, array( 'confirmada', 'reprogramada', 'cancelada' ), true ) ) {
			Inmo_Correo::aviso_visita( self::get( $id ), $nota );
		}
		return true;
	}

	/** Consultas de una propiedad (con nombre del contacto). */
	public static function de_propiedad( $propiedad_id, $tipo = null ) {
		global $wpdb;
		$sql = 'SELECT q.*, c.nombre, c.email, c.telefono FROM ' . self::tabla() . ' q LEFT JOIN ' . Inmo_Contactos::tabla() . ' c ON c.id = q.contacto_id WHERE q.propiedad_id = %d';
		$params = array( $propiedad_id );
		if ( $tipo ) {
			$sql     .= ' AND q.tipo = %s';
			$params[] = $tipo;
		}
		$sql .= ' ORDER BY q.id DESC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
	}

	public static function de_oportunidad( $oportunidad_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE oportunidad_id = %d ORDER BY id DESC', $oportunidad_id ) ); // phpcs:ignore
	}

	public static function de_contacto( $contacto_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE contacto_id = %d ORDER BY id DESC', $contacto_id ) ); // phpcs:ignore
	}

	public static function listar( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args( $args, array( 'tipo' => '', 'estado' => '', 'pagina' => 1, 'por_pagina' => 25, 'abiertas' => false ) );
		$where = array( Inmo_Permisos::sql_consultas_visibles( 'q' ) );
		if ( $args['tipo'] && isset( self::TIPOS[ $args['tipo'] ] ) ) {
			$where[] = $wpdb->prepare( 'q.tipo = %s', $args['tipo'] );
		}
		if ( $args['estado'] ) {
			$where[] = $wpdb->prepare( 'q.estado = %s', sanitize_key( $args['estado'] ) );
		}
		if ( $args['abiertas'] ) {
			$where[] = "q.estado IN ('nueva','en_gestion','pendiente','reprogramada')";
		}
		$w      = implode( ' AND ', $where );
		$offset = max( 0, ( (int) $args['pagina'] - 1 ) * (int) $args['por_pagina'] );
		$t      = self::tabla();
		$c      = Inmo_Contactos::tabla();
		$filas  = $wpdb->get_results( "SELECT q.*, c.nombre, c.email, c.telefono FROM {$t} q LEFT JOIN {$c} c ON c.id = q.contacto_id WHERE {$w} ORDER BY q.id DESC LIMIT " . (int) $args['por_pagina'] . ' OFFSET ' . $offset ); // phpcs:ignore
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} q WHERE {$w}" ); // phpcs:ignore
		return array( $filas, $total );
	}

	public static function contar( $tipo, array $estados ) {
		global $wpdb;
		$in = implode( ',', array_map( array( $wpdb, 'prepare' ), array_fill( 0, count( $estados ), '%s' ), $estados ) );
		$w  = Inmo_Permisos::sql_consultas_visibles( 'q' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::tabla() . " q WHERE q.tipo = %s AND q.estado IN ({$in}) AND {$w}", $tipo ) ); // phpcs:ignore
	}
}
