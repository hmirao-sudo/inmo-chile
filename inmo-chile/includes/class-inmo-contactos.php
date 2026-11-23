<?php
/**
 * CRM: contactos (personas) y su relación con propiedades como titulares.
 * Un contacto puede ser propietario, comprador o arrendatario a la vez.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Contactos {

	const ROLES = array(
		'propietario' => 'Propietario/a',
		'comprador'   => 'Comprador/a',
		'arrendatario' => 'Arrendatario/a',
	);

	const ORIGENES = array(
		'web_consulta'    => 'Web — consulta',
		'web_visita'      => 'Web — solicitud de visita',
		'web_propietario' => 'Web — propietario',
		'web_busqueda'    => 'Web — búsqueda sin resultados',
		'manual'          => 'Ingreso manual',
		'referido'        => 'Referido',
		'portal'          => 'Portal inmobiliario',
	);

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_contactos';
	}

	public static function tabla_relacion() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_propiedad_contacto';
	}

	public static function get( $id ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( $c ) {
			$c->preferencias = $c->preferencias ? json_decode( $c->preferencias, true ) : array();
			$c->roles_lista  = array_filter( explode( ',', (string) $c->roles ) );
		}
		return $c;
	}

	/** Busca un contacto existente por email o, si no hay email, por teléfono. */
	public static function buscar_existente( $email, $telefono ) {
		global $wpdb;
		$email = Inmo_Util::normalizar_email( $email );
		$tel   = Inmo_Util::normalizar_telefono( $telefono );
		if ( $email ) {
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::tabla() . ' WHERE email = %s ORDER BY id LIMIT 1', $email ) ); // phpcs:ignore
			if ( $id ) {
				return (int) $id;
			}
		}
		if ( $tel ) {
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::tabla() . " WHERE telefono = %s AND ( email = '' OR email = %s ) ORDER BY id LIMIT 1", $tel, $email ) ); // phpcs:ignore
			if ( $id ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Crea o actualiza (sin pisar datos existentes con vacíos) un contacto. Devuelve el ID.
	 */
	public static function upsert( array $d ) {
		global $wpdb;
		$email = Inmo_Util::normalizar_email( isset( $d['email'] ) ? $d['email'] : '' );
		$tel   = Inmo_Util::normalizar_telefono( isset( $d['telefono'] ) ? $d['telefono'] : '' );
		$id    = ! empty( $d['id'] ) ? (int) $d['id'] : self::buscar_existente( $email, $tel );

		$campos = array(
			'nombre'   => isset( $d['nombre'] ) ? sanitize_text_field( $d['nombre'] ) : '',
			'email'    => $email,
			'telefono' => $tel,
			'rut'      => isset( $d['rut'] ) ? Inmo_Util::normalizar_rut( $d['rut'] ) : '',
		);

		if ( $id ) {
			$actual = self::get( $id );
			if ( ! $actual ) {
				return 0;
			}
			$upd = array();
			foreach ( $campos as $k => $v ) {
				if ( '' !== $v && ( '' === (string) $actual->$k || ! empty( $d['sobrescribir'] ) ) ) {
					$upd[ $k ] = $v;
				}
			}
			if ( ! empty( $d['roles'] ) ) {
				$upd['roles'] = implode( ',', array_unique( array_merge( $actual->roles_lista, (array) $d['roles'] ) ) );
			}
			if ( ! empty( $d['preferencias'] ) && is_array( $d['preferencias'] ) ) {
				$upd['preferencias'] = wp_json_encode( array_merge( (array) $actual->preferencias, $d['preferencias'] ) );
			}
			if ( empty( $actual->corredor_id ) && ! empty( $d['corredor_id'] ) ) {
				$upd['corredor_id'] = (int) $d['corredor_id'];
			}
			if ( ! empty( $d['consentimiento'] ) ) {
				$upd['consentimiento'] = Inmo_Util::ahora();
			}
			if ( $upd ) {
				$upd['actualizado'] = Inmo_Util::ahora();
				$wpdb->update( self::tabla(), $upd, array( 'id' => $id ) );
			}
			return $id;
		}

		$roles = ! empty( $d['roles'] ) ? array_intersect( (array) $d['roles'], array_keys( self::ROLES ) ) : array();
		$wpdb->insert(
			self::tabla(),
			array_merge(
				$campos,
				array(
					'roles'          => implode( ',', $roles ),
					'origen'         => isset( $d['origen'] ) ? sanitize_key( $d['origen'] ) : 'manual',
					'corredor_id'    => ! empty( $d['corredor_id'] ) ? (int) $d['corredor_id'] : Inmo_Ajustes::corredor_defecto(),
					'preferencias'   => ! empty( $d['preferencias'] ) ? wp_json_encode( $d['preferencias'] ) : '',
					'consentimiento' => ! empty( $d['consentimiento'] ) ? Inmo_Util::ahora() : null,
					'creado'         => Inmo_Util::ahora(),
					'actualizado'    => Inmo_Util::ahora(),
				)
			)
		);
		$nuevo = (int) $wpdb->insert_id;
		if ( $nuevo ) {
			Inmo_Historial::registrar( 'contacto', 'Contacto creado (' . ( isset( $d['origen'] ) ? $d['origen'] : 'manual' ) . ')', array( 'contacto_id' => $nuevo ) );
		}
		return $nuevo;
	}

	/** Actualización explícita desde la ficha del contacto (sí permite vaciar). */
	public static function actualizar( $id, array $d ) {
		global $wpdb;
		$upd = array(
			'nombre'      => sanitize_text_field( $d['nombre'] ),
			'email'       => Inmo_Util::normalizar_email( $d['email'] ),
			'telefono'    => Inmo_Util::normalizar_telefono( $d['telefono'] ),
			'rut'         => Inmo_Util::normalizar_rut( $d['rut'] ),
			'roles'       => implode( ',', array_intersect( (array) $d['roles'], array_keys( self::ROLES ) ) ),
			'corredor_id' => (int) $d['corredor_id'],
			'preferencias' => wp_json_encode( $d['preferencias'] ),
			'actualizado' => Inmo_Util::ahora(),
		);
		return false !== $wpdb->update( self::tabla(), $upd, array( 'id' => (int) $id ) );
	}

	public static function vincular_titular( $propiedad_id, $contacto_id, $rol = 'titular' ) {
		global $wpdb;
		$existe = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . self::tabla_relacion() . ' WHERE propiedad_id = %d AND contacto_id = %d AND rol = %s', $propiedad_id, $contacto_id, $rol ) // phpcs:ignore
		);
		if ( $existe ) {
			return (int) $existe;
		}
		$wpdb->insert(
			self::tabla_relacion(),
			array(
				'propiedad_id' => (int) $propiedad_id,
				'contacto_id'  => (int) $contacto_id,
				'rol'          => sanitize_key( $rol ),
				'creado'       => Inmo_Util::ahora(),
			)
		);
		self::upsert( array( 'id' => $contacto_id, 'roles' => array( 'propietario' ) ) );
		Inmo_Historial::registrar( 'titular', 'Titular vinculado', array( 'propiedad_id' => $propiedad_id, 'contacto_id' => $contacto_id ) );
		return (int) $wpdb->insert_id;
	}

	public static function desvincular_titular( $propiedad_id, $contacto_id ) {
		global $wpdb;
		$wpdb->delete( self::tabla_relacion(), array( 'propiedad_id' => (int) $propiedad_id, 'contacto_id' => (int) $contacto_id, 'rol' => 'titular' ) );
		Inmo_Historial::registrar( 'titular', 'Titular desvinculado', array( 'propiedad_id' => $propiedad_id, 'contacto_id' => $contacto_id ) );
	}

	/** Titulares de una propiedad. */
	public static function titulares( $propiedad_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT c.* FROM ' . self::tabla() . ' c JOIN ' . self::tabla_relacion() . " r ON r.contacto_id = c.id WHERE r.propiedad_id = %d AND r.rol = 'titular' ORDER BY r.id", $propiedad_id ) // phpcs:ignore
		);
	}

	/** Propiedades donde el contacto es titular. */
	public static function propiedades_titular( $contacto_id ) {
		global $wpdb;
		return $wpdb->get_col(
			$wpdb->prepare( 'SELECT propiedad_id FROM ' . self::tabla_relacion() . " WHERE contacto_id = %d AND rol = 'titular'", $contacto_id ) // phpcs:ignore
		);
	}

	/** Listado con búsqueda y paginación, restringido a lo visible por el usuario. */
	public static function listar( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args( $args, array( 'buscar' => '', 'rol' => '', 'pagina' => 1, 'por_pagina' => 25 ) );
		$where = array( Inmo_Permisos::sql_contactos_visibles( 'c' ) );
		if ( $args['buscar'] ) {
			$like    = '%' . $wpdb->esc_like( $args['buscar'] ) . '%';
			$where[] = $wpdb->prepare( '( c.nombre LIKE %s OR c.email LIKE %s OR c.telefono LIKE %s OR c.rut LIKE %s )', $like, $like, $like, $like );
		}
		if ( $args['rol'] && isset( self::ROLES[ $args['rol'] ] ) ) {
			$where[] = $wpdb->prepare( 'FIND_IN_SET( %s, c.roles )', $args['rol'] );
		}
		$w      = implode( ' AND ', $where );
		$offset = max( 0, ( (int) $args['pagina'] - 1 ) * (int) $args['por_pagina'] );
		$t      = self::tabla();
		$q      = Inmo_Consultas::tabla();
		$filas  = $wpdb->get_results( "SELECT c.*, ( SELECT COUNT(*) FROM {$q} q WHERE q.contacto_id = c.id ) AS n_consultas FROM {$t} c WHERE {$w} ORDER BY c.actualizado DESC LIMIT " . (int) $args['por_pagina'] . ' OFFSET ' . $offset ); // phpcs:ignore
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} c WHERE {$w}" ); // phpcs:ignore
		return array( $filas, $total );
	}

	public static function etiqueta_roles( $roles ) {
		$roles = is_array( $roles ) ? $roles : array_filter( explode( ',', (string) $roles ) );
		$out   = array();
		foreach ( $roles as $r ) {
			if ( isset( self::ROLES[ $r ] ) ) {
				$out[] = self::ROLES[ $r ];
			}
		}
		return implode( ', ', $out );
	}
}
