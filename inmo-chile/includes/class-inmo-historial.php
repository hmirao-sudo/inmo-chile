<?php
/**
 * Bitácora de actividad por propiedad / contacto / consulta.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Historial {

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_historial';
	}

	public static function registrar( $accion, $detalle = '', array $ref = array() ) {
		global $wpdb;
		$wpdb->insert(
			self::tabla(),
			array(
				'propiedad_id' => isset( $ref['propiedad_id'] ) ? (int) $ref['propiedad_id'] : 0,
				'contacto_id'  => isset( $ref['contacto_id'] ) ? (int) $ref['contacto_id'] : 0,
				'consulta_id'  => isset( $ref['consulta_id'] ) ? (int) $ref['consulta_id'] : 0,
				'user_id'      => get_current_user_id(),
				'accion'       => substr( sanitize_key( $accion ), 0, 40 ),
				'detalle'      => wp_strip_all_tags( (string) $detalle ),
				'creado'       => Inmo_Util::ahora(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function de( $campo, $id, $limite = 50 ) {
		global $wpdb;
		$campo = in_array( $campo, array( 'propiedad_id', 'contacto_id', 'consulta_id' ), true ) ? $campo : 'propiedad_id';
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::tabla() . " WHERE {$campo} = %d ORDER BY id DESC LIMIT %d", $id, $limite ) // phpcs:ignore
		);
	}

	public static function etiqueta( $accion ) {
		$m = array(
			'captacion_web'     => 'Captación recibida desde la web',
			'consulta_web'      => 'Consulta recibida desde la web',
			'visita_solicitada' => 'Visita solicitada',
			'visita_confirmada' => 'Visita confirmada',
			'visita_reprogramada' => 'Visita reprogramada',
			'visita_cancelada'  => 'Visita cancelada',
			'visita_realizada'  => 'Visita realizada',
			'estado'            => 'Cambio de estado',
			'precio'            => 'Cambio de precio',
			'nota'              => 'Nota',
			'documento'         => 'Documento',
			'invitacion'        => 'Invitación al propietario',
			'propietario_completo' => 'Propietario completó la ficha',
			'foto_autorizada'   => 'Foto autorizada',
			'correo'            => 'Correo',
			'consulta_estado'   => 'Consulta actualizada',
			'contacto'          => 'Contacto',
			'titular'           => 'Titular',
			'valorizacion'      => 'Solicitud de valorización',
			'busqueda'          => 'Búsqueda sin resultados',
		);
		return isset( $m[ $accion ] ) ? $m[ $accion ] : ucfirst( str_replace( '_', ' ', $accion ) );
	}
}
