<?php
/**
 * Ajustes: marca, contacto, UF, códigos, invitaciones.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Ajustes {

	const OPCION = 'inmo_ajustes';

	public static function defaults() {
		return array(
			// Marca (supuestos reversibles; se reemplazan desde Inmobiliaria → Ajustes).
			'marca_nombre'        => 'Cordillera Propiedades',
			'marca_eslogan'       => 'Corretaje de propiedades en Santiago',
			'color_primario'      => '#1f4e5f',
			'color_acento'        => '#d9822b',
			'logo_id'             => 0,
			'telefono'            => '+56 2 2345 6789',
			'whatsapp'            => '+56 9 8765 4321',
			'email'               => 'contacto@example.test',
			'direccion_oficina'   => 'Av. Ejemplo 1234, oficina 56, Providencia',
			'horario'             => 'Lunes a viernes, 9:00 a 18:30',
			// Valores.
			'uf_valor'            => '39500.00',
			'uf_fecha'            => '2026-11-23',
			'uf_fuente'           => 'Valor de referencia ingresado manualmente (verificar en sii.cl)',
			// Operación.
			'codigo_prefijo'      => 'CP',
			'precision_defecto'   => 'aproximada',
			'invitacion_dias'     => 7,
			'corredor_defecto'    => 0,
			'email_notificaciones' => '',
			'resultados_pagina'   => 12,
			'respuesta_plazo'     => '1 día hábil',
			// Administración de arriendos.
			'admin_comision_pct'  => '7',
			'iva_pct'             => '19',
			'cobro_dia_generacion' => 1,
			'uf_fuente_auto'      => 'manual',
			'cmf_api_key'         => '',
			// Valorización automática.
			'valorizacion_publica'   => 1,
			'valorizacion_min'       => 3,
			'valorizacion_meses'     => 24,
			'valorizacion_descuento' => '3',
			'ajuste_estac_venta'     => '300',
			'ajuste_estac_arriendo'  => '30000',
			'datos_pago'          => 'Transferencia a Cordillera Propiedades SpA, RUT 76.000.000-0, Banco Ejemplo, cuenta corriente 000-00000-00, pagos@example.test',
		);
	}

	public static function todos() {
		$guardado = get_option( self::OPCION, array() );
		return wp_parse_args( is_array( $guardado ) ? $guardado : array(), self::defaults() );
	}

	public static function get( $clave, $defecto = '' ) {
		$t = self::todos();
		return ( isset( $t[ $clave ] ) && '' !== $t[ $clave ] ) ? $t[ $clave ] : $defecto;
	}

	public static function guardar( array $valores ) {
		$actual = self::todos();
		$uf_antes = (float) $actual['uf_valor'];
		$nuevo  = array_merge( $actual, $valores );
		update_option( self::OPCION, $nuevo, true );
		if ( abs( (float) $nuevo['uf_valor'] - $uf_antes ) > 0.001 ) {
			Inmo_Propiedades::recalcular_precios_referencia();
		}
	}

	public static function uf() {
		return max( 1.0, (float) self::get( 'uf_valor', 1 ) );
	}

	/** Correo que recibe avisos de nuevas consultas (si no hay corredor asignado). */
	public static function email_avisos() {
		$e = self::get( 'email_notificaciones' );
		return $e ? $e : get_option( 'admin_email' );
	}

	/** Usuario corredor por defecto para captaciones y consultas generales. */
	public static function corredor_defecto() {
		$id = (int) self::get( 'corredor_defecto', 0 );
		if ( $id && get_userdata( $id ) ) {
			return $id;
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) );
		return $admins ? (int) $admins[0] : 1;
	}
}
