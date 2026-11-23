<?php
/**
 * Roles, capacidades y reglas de acceso a datos sensibles.
 *
 * Roles:
 *  - administrator: todo, incluida la aprobación de captaciones y los ajustes.
 *  - inmo_corredor: gestiona SUS propiedades (autor = corredor responsable), sus contactos y consultas.
 *  - visitante (anónimo): solo la vista pública saneada.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Permisos {

	const ROL_CORREDOR = 'inmo_corredor';

	/** Capacidades primitivas del tipo de contenido. */
	public static function caps_cpt() {
		return array(
			'edit_inmo_propiedades',
			'edit_others_inmo_propiedades',
			'edit_published_inmo_propiedades',
			'edit_private_inmo_propiedades',
			'publish_inmo_propiedades',
			'read_private_inmo_propiedades',
			'delete_inmo_propiedades',
			'delete_others_inmo_propiedades',
			'delete_published_inmo_propiedades',
			'delete_private_inmo_propiedades',
		);
	}

	public static function registrar_roles() {
		$corredor = array(
			'read'                            => true,
			'upload_files'                    => true,
			'edit_inmo_propiedades'           => true,
			'edit_published_inmo_propiedades' => true,
			'edit_private_inmo_propiedades'   => true,
			'delete_inmo_propiedades'         => true,
			'inmo_gestionar'                  => true,
		);
		remove_role( self::ROL_CORREDOR );
		add_role( self::ROL_CORREDOR, 'Corredor/a de propiedades', $corredor );
		if ( ! get_role( 'inmo_propietario' ) ) {
			add_role( 'inmo_propietario', 'Propietario/a (portal)', array( 'read' => true ) );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::caps_cpt() as $cap ) {
				$admin->add_cap( $cap );
			}
			foreach ( array( 'inmo_gestionar', 'inmo_ver_todo', 'inmo_aprobar', 'inmo_ajustes' ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function quitar_roles() {
		remove_role( self::ROL_CORREDOR );
		remove_role( 'inmo_propietario' );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( self::caps_cpt(), array( 'inmo_gestionar', 'inmo_ver_todo', 'inmo_aprobar', 'inmo_ajustes' ) ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	public static function ve_todo( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return $user_id && user_can( $user_id, 'inmo_ver_todo' );
	}

	/**
	 * Datos privados de una propiedad (titulares, RUT, dirección exacta, mandato, notas, documentos):
	 * solo administración o el corredor responsable.
	 */
	public static function puede_ver_privado( $post_id, $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( self::ve_todo( $user_id ) ) {
			return true;
		}
		$post = get_post( $post_id );
		return $post && INMO_CPT === $post->post_type
			&& (int) $post->post_author === $user_id
			&& user_can( $user_id, 'edit_post', $post->ID );
	}

	/** ¿Puede el usuario ver un contacto del CRM? */
	public static function puede_ver_contacto( $contacto_id, $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'inmo_gestionar' ) ) {
			return false;
		}
		if ( self::ve_todo( $user_id ) ) {
			return true;
		}
		global $wpdb;
		$sql = 'SELECT c.id FROM ' . Inmo_Contactos::tabla() . ' c WHERE c.id = %d AND ' . self::sql_contactos_visibles( 'c' );
		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $contacto_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Condición SQL (ya escapada) de contactos visibles para el usuario actual. */
	public static function sql_contactos_visibles( $alias = 'c', $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( self::ve_todo( $user_id ) ) {
			return '1=1';
		}
		$q  = Inmo_Consultas::tabla();
		$pc = Inmo_Contactos::tabla_relacion();
		return $wpdb->prepare(
			"( {$alias}.corredor_id = %d
			OR EXISTS ( SELECT 1 FROM {$q} q WHERE q.contacto_id = {$alias}.id AND q.corredor_id = %d )
			OR EXISTS ( SELECT 1 FROM {$pc} pc JOIN {$wpdb->posts} p ON p.ID = pc.propiedad_id WHERE pc.contacto_id = {$alias}.id AND p.post_author = %d ) )",
			$user_id,
			$user_id,
			$user_id
		);
	}

	/** Condición SQL de consultas visibles. */
	public static function sql_consultas_visibles( $alias = 'q', $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( self::ve_todo( $user_id ) ) {
			return '1=1';
		}
		return $wpdb->prepare(
			"( {$alias}.corredor_id = %d OR EXISTS ( SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = {$alias}.propiedad_id AND p.post_author = %d ) )",
			$user_id,
			$user_id
		);
	}

	public static function puede_ver_consulta( $consulta ) {
		if ( ! $consulta || ! current_user_can( 'inmo_gestionar' ) ) {
			return false;
		}
		if ( self::ve_todo() ) {
			return true;
		}
		$uid = get_current_user_id();
		if ( (int) $consulta->corredor_id === $uid ) {
			return true;
		}
		return $consulta->propiedad_id && self::puede_ver_privado( $consulta->propiedad_id );
	}

	/** Corredores disponibles para asignar. */
	public static function corredores() {
		return get_users(
			array(
				'role__in' => array( 'administrator', self::ROL_CORREDOR ),
				'orderby'  => 'display_name',
				'fields'   => array( 'ID', 'display_name' ),
			)
		);
	}
}
