<?php
/**
 * Expediente por propiedad: documentos emitidos y firmados, antecedentes legales para la compraventa
 * y archivo de la escritura definitiva.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Expediente {

	/** Antecedentes habituales para una compraventa en Chile (lista editable con el filtro inmo_antecedentes). */
	public static function antecedentes( $operacion ) {
		if ( 'venta' !== $operacion ) {
			$l = array(
				'dominio_vigente' => 'Certificado de dominio vigente',
				'gastos_comunes'  => 'Certificado de gastos comunes al día',
				'cedula_partes'   => 'Cédulas de identidad de las partes',
				'liquidaciones'   => 'Antecedentes del arrendatario (liquidaciones, DICOM)',
			);
		} else {
			$l = array(
				'dominio_vigente'  => 'Certificado de dominio vigente',
				'hipotecas'        => 'Certificado de hipotecas, gravámenes y prohibiciones',
				'titulos'          => 'Copia de inscripciones (títulos de los últimos 10 años)',
				'avaluo'           => 'Certificado de avalúo fiscal',
				'contribuciones'   => 'Certificado de deuda de contribuciones',
				'no_expropiacion'  => 'Certificado de no expropiación (SERVIU / municipal)',
				'recepcion_final'  => 'Certificado de recepción final de obras',
				'gastos_comunes'   => 'Certificado de gastos comunes al día',
				'cedula_partes'    => 'Cédulas de identidad de las partes',
			);
		}
		return apply_filters( 'inmo_antecedentes', $l, $operacion );
	}

	/** Estado de cada antecedente: el último documento subido de ese subtipo. */
	public static function estado_antecedentes( $propiedad_id ) {
		$op  = Inmo_Propiedades::termino( $propiedad_id, Inmo_Propiedades::TAX_OPERACION );
		$out = array();
		$docs = Inmo_Documentos::de_propiedad( $propiedad_id, 'antecedente' );
		foreach ( self::antecedentes( $op ) as $k => $l ) {
			$out[ $k ] = array( 'label' => $l, 'doc' => null );
			foreach ( $docs as $d ) {
				if ( $d->subtipo === $k ) {
					$out[ $k ]['doc'] = $d;
					break;
				}
			}
		}
		return $out;
	}

	public static function escritura( $propiedad_id ) {
		$e = Inmo_Documentos::de_propiedad( $propiedad_id, 'escritura' );
		return $e ? $e[0] : null;
	}
}
