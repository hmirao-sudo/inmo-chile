<?php
/**
 * Emisión de documentos desde plantillas: completa datos de partes, inmueble, montos, moneda y fechas;
 * señala lo que falta; ofrece vista previa; emite un PDF inmutable y conserva cada versión emitida.
 * La emisión y la firma tienen estados propios, independientes del cierre de la operación.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Contratos {

	const ESTADOS_EMISION = array(
		'emitido' => 'Emitido',
		'anulado' => 'Anulado',
	);

	const ESTADOS_FIRMA = array(
		'pendiente' => 'Firma pendiente',
		'parcial'   => 'Firmado parcialmente',
		'firmado'   => 'Firmado por todas las partes',
		'rechazado' => 'Firma rechazada',
	);

	const PREFIJOS = array(
		'mandato'      => 'MAN',
		'orden_visita' => 'OV',
		'reserva'      => 'RES',
		'arriendo'     => 'ARR',
		'promesa'      => 'PRO',
	);

	public static function init() {
		// Reservado para el conector de firma electrónica (Entrega 3).
	}

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_emisiones';
	}

	/** Catálogo de variables y su descripción (lo que falte se informa con esta etiqueta). */
	public static function variables() {
		return array(
			'documento.numero'          => 'Número del documento (se asigna al emitir)',
			'fecha.hoy'                 => 'Fecha de emisión (en palabras)',
			'corredora.nombre'          => 'Nombre de la corredora',
			'corredora.ciudad'          => 'Ciudad de la corredora',
			'corredora.direccion'       => 'Dirección de la corredora',
			'corredor.nombre'           => 'Corredor responsable',
			'propiedad.codigo'          => 'Código de la propiedad',
			'propiedad.tipo'            => 'Tipo de propiedad',
			'propiedad.operacion'       => 'Operación (venta / arriendo)',
			'propiedad.direccion'       => 'Dirección exacta de la propiedad',
			'propiedad.comuna'          => 'Comuna',
			'propiedad.region'          => 'Región',
			'propiedad.rol_sii'         => 'Rol SII',
			'precio.publicado'          => 'Precio o renta publicada',
			'precio.publicado_palabras' => 'Precio publicado en palabras',
			'mandato.operacion'         => 'Encargo (venta / arriendo / arriendo y administración)',
			'mandato.exclusividad'      => 'Exclusividad del mandato',
			'mandato.comision'          => 'Comisión pactada',
			'mandato.vence'             => 'Vencimiento del mandato',
			'propietario.nombre'        => 'Nombre del propietario (primer titular)',
			'propietario.rut'           => 'RUT del propietario',
			'propietarios.lista'        => 'Todos los titulares con RUT',
			'cliente.nombre'            => 'Nombre del cliente de la oportunidad',
			'cliente.rut'               => 'RUT del cliente',
			'cliente.email'             => 'Correo del cliente',
			'cliente.telefono'          => 'Teléfono del cliente',
			'arrendador.nombre'         => 'Nombre del arrendador (titular)',
			'arrendador.rut'            => 'RUT del arrendador',
			'arrendatario.nombre'       => 'Nombre del arrendatario',
			'arrendatario.rut'          => 'RUT del arrendatario',
			'vendedor.nombre'           => 'Nombre del vendedor (titular)',
			'vendedor.rut'              => 'RUT del vendedor',
			'comprador.nombre'          => 'Nombre del comprador',
			'comprador.rut'             => 'RUT del comprador',
			'operacion.precio'          => 'Precio o renta acordada (o publicada)',
			'arriendo.renta'            => 'Renta mensual acordada',
			'arriendo.renta_palabras'   => 'Renta en palabras',
			'arriendo.garantia'         => 'Monto de la garantía',
			'arriendo.garantia_meses'   => 'Garantía en meses de renta',
			'arriendo.reajuste'         => 'Reajuste',
			'arriendo.plazo_meses'      => 'Plazo del contrato (meses)',
			'arriendo.fecha_inicio'     => 'Fecha de inicio del arriendo',
			'arriendo.dia_pago'         => 'Día de pago',
			'arriendo.gastos_comunes'   => 'Gastos comunes',
			'aval.nombre'               => 'Nombre del aval',
			'aval.rut'                  => 'RUT del aval',
			'venta.precio'              => 'Precio de venta acordado',
			'venta.precio_palabras'     => 'Precio de venta en palabras',
			'venta.pie'                 => 'Pie',
			'venta.credito'             => 'Forma de pago del saldo',
			'venta.fecha_promesa'       => 'Fecha de la promesa',
			'venta.fecha_escritura'     => 'Fecha prevista de escritura',
			'venta.notaria'             => 'Notaría',
			'reserva.monto'             => 'Monto de la reserva',
			'reserva.monto_palabras'    => 'Reserva en palabras',
			'reserva.fecha'             => 'Fecha de la reserva',
			'visita.fecha'              => 'Fecha de la visita confirmada',
		);
	}

	/* =====================================================================
	 * Datos
	 * =================================================================== */

	/**
	 * Arma los valores de las variables para una propiedad y, si corresponde, una oportunidad.
	 */
	public static function datos( $propiedad_id, $oportunidad_id = 0 ) {
		$d   = array();
		$o   = $oportunidad_id ? Inmo_Oportunidades::get( $oportunidad_id ) : null;
		$priv = Inmo_Privado::get( $propiedad_id );
		$tipo = Inmo_Propiedades::termino( $propiedad_id, Inmo_Propiedades::TAX_TIPO );
		$op   = Inmo_Propiedades::termino( $propiedad_id, Inmo_Propiedades::TAX_OPERACION );
		$com  = Inmo_Propiedades::comuna( $propiedad_id );
		$reg  = ( $com && $com->parent ) ? get_term( $com->parent, Inmo_Propiedades::TAX_UBICACION ) : null;
		$precio = get_post_meta( $propiedad_id, '_inmo_precio', true );
		$moneda = get_post_meta( $propiedad_id, '_inmo_moneda', true );
		$cond   = $o ? $o->condiciones : array();

		$d['fecha.hoy']           = self::fecha_larga( current_time( 'Y-m-d' ) );
		$d['corredora.nombre']    = Inmo_Ajustes::get( 'marca_nombre' );
		$d['corredora.direccion'] = Inmo_Ajustes::get( 'direccion_oficina' );
		$d['corredora.ciudad']    = 'Santiago';
		$d['corredor.nombre']     = get_the_author_meta( 'display_name', get_post_field( 'post_author', $propiedad_id ) );

		$d['propiedad.codigo']    = get_post_meta( $propiedad_id, '_inmo_codigo', true );
		$d['propiedad.tipo']      = $tipo ? strtolower( Inmo_Esquema::TIPOS[ $tipo ] ) : '';
		$d['propiedad.operacion'] = $op ? strtolower( Inmo_Esquema::OPERACIONES[ $op ] ) : '';
		$d['propiedad.direccion'] = trim( $priv['direccion'] . ( $priv['unidad'] ? ', ' . $priv['unidad'] : '' ) );
		$d['propiedad.comuna']    = $com ? $com->name : '';
		$d['propiedad.region']    = ( $reg && ! is_wp_error( $reg ) ) ? $reg->name : '';
		$d['propiedad.rol_sii']   = $priv['rol_sii'];
		$d['precio.publicado']    = Inmo_Util::formatear_monto( $precio, $moneda );
		$d['precio.publicado_palabras'] = self::monto_palabras( $precio, $moneda );

		$mandatos = array( 'corretaje' => 'arriendo' === $op ? 'corretaje para arriendo' : 'corretaje para venta', 'corretaje_admin' => 'corretaje para arriendo y administración' );
		$d['mandato.operacion']    = $priv['mandato_tipo'] ? $mandatos[ $priv['mandato_tipo'] ] : '';
		$d['mandato.exclusividad'] = $priv['mandato_tipo'] ? ( 'si' === $priv['exclusividad'] ? 'exclusivo' : 'sin exclusividad' ) : '';
		$d['mandato.comision']     = '' !== (string) $priv['comision_pct'] && null !== $priv['comision_pct'] ? rtrim( rtrim( number_format( (float) $priv['comision_pct'], 2, ',', '.' ), '0' ), ',' ) . '%' . ( $priv['comision_detalle'] ? ' (' . $priv['comision_detalle'] . ')' : '' ) : '';
		$d['mandato.vence']        = $priv['captacion_vence'] ? self::fecha_larga( $priv['captacion_vence'] ) : '';

		$tit = Inmo_Contactos::titulares( $propiedad_id );
		$p   = $tit ? $tit[0] : null;
		$d['propietario.nombre'] = $p ? $p->nombre : '';
		$d['propietario.rut']    = $p ? Inmo_Util::formatear_rut( $p->rut ) : '';
		$lista = array();
		foreach ( $tit as $t ) {
			$lista[] = $t->nombre . ( $t->rut ? ', RUT ' . Inmo_Util::formatear_rut( $t->rut ) : '' );
		}
		$d['propietarios.lista'] = implode( '; ', $lista );
		$d['arrendador.nombre']  = $d['vendedor.nombre'] = $d['propietario.nombre'];
		$d['arrendador.rut']     = $d['vendedor.rut'] = $d['propietario.rut'];

		$c = $o ? Inmo_Contactos::get( $o->contacto_id ) : null;
		$d['cliente.nombre']   = $c ? $c->nombre : '';
		$d['cliente.rut']      = $c ? Inmo_Util::formatear_rut( $c->rut ) : '';
		$d['cliente.email']    = $c ? $c->email : '';
		$d['cliente.telefono'] = $c ? $c->telefono : '';
		$d['arrendatario.nombre'] = $d['comprador.nombre'] = $d['cliente.nombre'];
		$d['arrendatario.rut']    = $d['comprador.rut'] = $d['cliente.rut'];

		// Precio acordado: condición de la oportunidad > oferta aceptada > publicado.
		$acordado = isset( $cond['precio_acordado'] ) ? $cond['precio_acordado'] : $precio;
		$mon_ac   = ! empty( $cond['moneda_acordada'] ) ? $cond['moneda_acordada'] : $moneda;
		$d['operacion.precio'] = Inmo_Util::formatear_monto( $acordado, $mon_ac );

		if ( 'arriendo' === $op ) {
			$gm = isset( $cond['garantia_meses'] ) ? $cond['garantia_meses'] : get_post_meta( $propiedad_id, '_inmo_garantia_meses', true );
			$plazo = isset( $cond['plazo_meses'] ) ? $cond['plazo_meses'] : get_post_meta( $propiedad_id, '_inmo_vigencia_meses', true );
			$reaj  = get_post_meta( $propiedad_id, '_inmo_reajuste', true );
			$campos = Inmo_Esquema::campos();
			$d['arriendo.renta']          = Inmo_Util::formatear_monto( $acordado, $mon_ac );
			$d['arriendo.renta_palabras'] = self::monto_palabras( $acordado, $mon_ac );
			$d['arriendo.garantia_meses'] = '' !== (string) $gm ? rtrim( rtrim( number_format( (float) $gm, 1, ',', '' ), '0' ), ',' ) : '';
			$d['arriendo.garantia']       = ( '' !== (string) $gm && '' !== (string) $acordado ) ? Inmo_Util::formatear_monto( round( (float) $gm * (float) $acordado, 2 ), $mon_ac ) : '';
			$d['arriendo.reajuste']       = $reaj ? $campos['reajuste']['opciones'][ $reaj ] : '';
			$d['arriendo.plazo_meses']    = '' !== (string) $plazo ? (string) (int) $plazo : '';
			$d['arriendo.fecha_inicio']   = ! empty( $cond['fecha_inicio'] ) ? self::fecha_larga( $cond['fecha_inicio'] ) : '';
			$d['arriendo.dia_pago']       = ! empty( $cond['dia_pago'] ) ? (string) (int) $cond['dia_pago'] : '';
			$gc = get_post_meta( $propiedad_id, '_inmo_gastos_comunes', true );
			$d['arriendo.gastos_comunes'] = '' !== (string) $gc ? Inmo_Util::formatear_monto( $gc, 'CLP' ) : '';
			$d['aval.nombre'] = isset( $cond['aval_nombre'] ) ? $cond['aval_nombre'] : '';
			$d['aval.rut']    = isset( $cond['aval_rut'] ) ? 'RUT ' . Inmo_Util::formatear_rut( $cond['aval_rut'] ) : '';
		} else {
			$d['venta.precio']          = Inmo_Util::formatear_monto( $acordado, $mon_ac );
			$d['venta.precio_palabras'] = self::monto_palabras( $acordado, $mon_ac );
			$d['venta.pie']             = isset( $cond['pie'] ) ? Inmo_Util::formatear_monto( $cond['pie'], $mon_ac ) : '';
			$d['venta.credito']         = isset( $cond['credito'] ) ? ( 'si' === $cond['credito'] ? 'mediante crédito hipotecario' : 'al contado' ) : '';
			$d['venta.fecha_promesa']   = ! empty( $cond['fecha_promesa'] ) ? self::fecha_larga( $cond['fecha_promesa'] ) : '';
			$d['venta.fecha_escritura'] = ! empty( $cond['fecha_escritura'] ) ? self::fecha_larga( $cond['fecha_escritura'] ) : '';
			$d['venta.notaria']         = isset( $cond['notaria'] ) ? $cond['notaria'] : '';
		}
		$rmon = isset( $cond['reserva_moneda'] ) ? $cond['reserva_moneda'] : 'CLP';
		$d['reserva.monto']          = isset( $cond['reserva_monto'] ) ? Inmo_Util::formatear_monto( $cond['reserva_monto'], $rmon ) : '';
		$d['reserva.monto_palabras'] = isset( $cond['reserva_monto'] ) ? self::monto_palabras( $cond['reserva_monto'], $rmon ) : '';
		$d['reserva.fecha']          = ! empty( $cond['reserva_fecha'] ) ? self::fecha_larga( $cond['reserva_fecha'] ) : '';

		$d['visita.fecha'] = '';
		if ( $o ) {
			foreach ( Inmo_Consultas::de_oportunidad( $o->id ) as $q ) {
				if ( 'visita' === $q->tipo && $q->visita_fecha && in_array( $q->estado, array( 'confirmada', 'realizada', 'reprogramada' ), true ) ) {
					$d['visita.fecha'] = Inmo_Util::fecha( $q->visita_fecha, true );
					break;
				}
			}
		}
		$d['documento.numero'] = '(se asigna al emitir)';
		return apply_filters( 'inmo_datos_documento', $d, $propiedad_id, $oportunidad_id );
	}

	/* =====================================================================
	 * Render
	 * =================================================================== */

	/**
	 * @return array [ 'html' => string, 'faltan' => [ var => etiqueta ], 'usadas' => [...] ]
	 */
	public static function render( $contenido, array $datos, $marcar_faltantes = true ) {
		$vars   = self::variables();
		$faltan = array();
		$usadas = array();
		$html   = preg_replace_callback(
			'/\{\{\s*([a-z_]+\.[a-z_]+)\s*(\?)?\s*\}\}/',
			static function ( $m ) use ( $datos, $vars, &$faltan, &$usadas, $marcar_faltantes ) {
				$k          = $m[1];
				$opcional   = ! empty( $m[2] );
				$usadas[ $k ] = true;
				$v          = isset( $datos[ $k ] ) ? trim( (string) $datos[ $k ] ) : '';
				if ( '' === $v ) {
					if ( ! $opcional ) {
						$faltan[ $k ] = isset( $vars[ $k ] ) ? $vars[ $k ] : $k;
						return $marcar_faltantes ? '<mark class="inmo-falta">[falta: ' . esc_html( isset( $vars[ $k ] ) ? $vars[ $k ] : $k ) . ']</mark>' : '';
					}
					return '';
				}
				return esc_html( $v );
			},
			$contenido
		);
		return array( 'html' => wpautop( $html ), 'faltan' => $faltan, 'usadas' => array_keys( $usadas ) );
	}

	/** Contexto válido para usar una plantilla. */
	public static function validar_contexto( $plantilla, $propiedad_id, $oportunidad_id ) {
		if ( ! $plantilla || Inmo_Plantillas::CPT !== $plantilla->post_type || 'publish' !== $plantilla->post_status ) {
			return new WP_Error( 'inmo_plantilla', 'Plantilla no válida.' );
		}
		$tipo = get_post_meta( $plantilla->ID, '_inmo_tipo_doc', true );
		if ( 'oportunidad' === ( Inmo_Plantillas::CONTEXTO[ $tipo ] ?? '' ) && ! $oportunidad_id ) {
			return new WP_Error( 'inmo_contexto', 'Este documento se emite desde una oportunidad.' );
		}
		$op_req = Inmo_Plantillas::OPERACION[ $tipo ] ?? '';
		if ( $op_req && Inmo_Propiedades::termino( $propiedad_id, Inmo_Propiedades::TAX_OPERACION ) !== $op_req ) {
			return new WP_Error( 'inmo_operacion', 'La plantilla no corresponde a la operación de la propiedad.' );
		}
		if ( ! Inmo_Permisos::puede_ver_privado( $propiedad_id ) ) {
			return new WP_Error( 'inmo_permiso', 'No tienes permiso para emitir documentos de esta propiedad.' );
		}
		return true;
	}

	public static function previsualizar( $plantilla_id, $propiedad_id, $oportunidad_id = 0 ) {
		$pl = get_post( $plantilla_id );
		$ok = self::validar_contexto( $pl, $propiedad_id, $oportunidad_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$r            = self::render( $pl->post_content, self::datos( $propiedad_id, $oportunidad_id ) );
		$r['version'] = (int) get_post_meta( $plantilla_id, '_inmo_version', true );
		$r['titulo']  = $pl->post_title;
		return $r;
	}

	/**
	 * Emite el documento: valida faltantes, numera, congela HTML y datos, genera PDF privado.
	 *
	 * @return int|WP_Error ID de la emisión.
	 */
	public static function emitir( $plantilla_id, $propiedad_id, $oportunidad_id = 0 ) {
		global $wpdb;
		$pl = get_post( $plantilla_id );
		$ok = self::validar_contexto( $pl, $propiedad_id, $oportunidad_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$tipo   = get_post_meta( $plantilla_id, '_inmo_tipo_doc', true );
		$datos  = self::datos( $propiedad_id, $oportunidad_id );
		$prueba = self::render( $pl->post_content, $datos );
		if ( $prueba['faltan'] ) {
			return new WP_Error( 'inmo_faltan', 'No se puede emitir. Falta: ' . implode( ', ', $prueba['faltan'] ) . '.', $prueba['faltan'] );
		}
		$numero                    = self::siguiente_numero( $tipo );
		$datos['documento.numero'] = $numero;
		$r                         = self::render( $pl->post_content, $datos, false );
		$titulo                    = $pl->post_title . ' — ' . $numero;
		$html                      = self::documento_html( $titulo, $r['html'], $numero, (int) get_post_meta( $plantilla_id, '_inmo_version', true ) );

		$pdf = Inmo_Pdf::generar( $html );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		$o      = $oportunidad_id ? Inmo_Oportunidades::get( $oportunidad_id ) : null;
		$doc_id = Inmo_Documentos::guardar_contenido( $pdf, 'pdf', $propiedad_id, 'contrato', sanitize_file_name( strtolower( $numero ) . '.pdf' ), 'application/pdf', $o ? $o->contacto_id : 0, $tipo );
		if ( is_wp_error( $doc_id ) ) {
			return $doc_id;
		}
		$wpdb->insert(
			self::tabla(),
			array(
				'numero'                => $numero,
				'tipo'                  => $tipo,
				'propiedad_id'          => (int) $propiedad_id,
				'oportunidad_id'        => (int) $oportunidad_id,
				'plantilla_id'          => (int) $plantilla_id,
				'plantilla_version'     => (int) get_post_meta( $plantilla_id, '_inmo_version', true ),
				'plantilla_revision_id' => Inmo_Plantillas::revision_actual( $plantilla_id ),
				'titulo'                => $titulo,
				'html'                  => $html,
				'datos'                 => wp_json_encode( $datos ),
				'documento_id'          => (int) $doc_id,
				'sha256'                => hash( 'sha256', $pdf ),
				'estado_emision'        => 'emitido',
				'estado_firma'          => 'pendiente',
				'firmantes'             => wp_json_encode( self::firmantes( $tipo, $datos ) ),
				'user_id'               => get_current_user_id(),
				'creado'                => Inmo_Util::ahora(),
				'actualizado'           => Inmo_Util::ahora(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $o && 'arriendo' === $tipo || $o && 'promesa' === $tipo ) {
			Inmo_Oportunidades::avanzar_a( $oportunidad_id, 'contrato' );
		}
		Inmo_Historial::registrar( 'emision', $titulo . ' (plantilla v' . get_post_meta( $plantilla_id, '_inmo_version', true ) . ')', array( 'propiedad_id' => $propiedad_id, 'contacto_id' => $o ? $o->contacto_id : 0, 'oportunidad_id' => $oportunidad_id ) );
		return $id;
	}

	private static function firmantes( $tipo, array $d ) {
		$f = array();
		switch ( $tipo ) {
			case 'arriendo':
				$f[] = array( 'rol' => 'Arrendador', 'nombre' => $d['arrendador.nombre'] );
				$f[] = array( 'rol' => 'Arrendatario', 'nombre' => $d['arrendatario.nombre'] );
				if ( ! empty( $d['aval.nombre'] ) ) {
					$f[] = array( 'rol' => 'Aval', 'nombre' => $d['aval.nombre'] );
				}
				break;
			case 'promesa':
				$f[] = array( 'rol' => 'Promitente vendedor', 'nombre' => $d['vendedor.nombre'] );
				$f[] = array( 'rol' => 'Promitente comprador', 'nombre' => $d['comprador.nombre'] );
				break;
			case 'mandato':
				$f[] = array( 'rol' => 'Mandante', 'nombre' => $d['propietario.nombre'] );
				$f[] = array( 'rol' => 'Corredora', 'nombre' => $d['corredora.nombre'] );
				break;
			case 'reserva':
			case 'orden_visita':
				$f[] = array( 'rol' => 'Cliente', 'nombre' => $d['cliente.nombre'] );
				break;
		}
		foreach ( $f as &$x ) {
			$x['estado'] = 'pendiente';
			$x['fecha']  = '';
		}
		return $f;
	}

	private static function siguiente_numero( $tipo ) {
		global $wpdb;
		$pref = ( self::PREFIJOS[ $tipo ] ?? 'DOC' ) . '-' . current_time( 'Y' ) . '-';
		$ult  = $wpdb->get_var( $wpdb->prepare( 'SELECT numero FROM ' . self::tabla() . ' WHERE numero LIKE %s ORDER BY id DESC LIMIT 1', $wpdb->esc_like( $pref ) . '%' ) ); // phpcs:ignore
		$n    = $ult ? (int) substr( $ult, strlen( $pref ) ) + 1 : 1;
		return $pref . str_pad( (string) $n, 4, '0', STR_PAD_LEFT );
	}

	public static function documento_html( $titulo, $cuerpo, $numero, $version ) {
		$css = 'body{font-family:DejaVu Sans,sans-serif;font-size:11pt;line-height:1.5;color:#111;margin:0}h1{font-size:15pt;text-align:center;margin:0 0 14pt}p{margin:0 0 9pt;text-align:justify}'
			. '.pie{position:fixed;bottom:-20pt;left:0;right:0;font-size:8pt;color:#666;text-align:center}@page{margin:60pt 56pt}';
		return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>' . esc_html( $titulo ) . '</title><style>' . $css . '</style></head><body>'
			. '<div class="pie">' . esc_html( $numero . ' · ' . Inmo_Ajustes::get( 'marca_nombre' ) . ' · plantilla v' . $version . ' · emitido el ' . Inmo_Util::fecha( Inmo_Util::ahora(), true ) ) . '</div>'
			. $cuerpo . '</body></html>';
	}

	/* =====================================================================
	 * Consulta, firma y anulación
	 * =================================================================== */

	public static function get( $id ) {
		global $wpdb;
		$e = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id ) ); // phpcs:ignore
		if ( $e ) {
			$e->firmantes = $e->firmantes ? (array) json_decode( $e->firmantes, true ) : array();
		}
		return $e;
	}

	public static function de_propiedad( $propiedad_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . self::tabla() . ' WHERE propiedad_id = %d ORDER BY id DESC', $propiedad_id ) ); // phpcs:ignore
		return array_map( static function ( $r ) { return Inmo_Contratos::get( $r->id ); }, $rows );
	}

	public static function de_oportunidad( $oportunidad_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id FROM ' . self::tabla() . ' WHERE oportunidad_id = %d ORDER BY id DESC', $oportunidad_id ) ); // phpcs:ignore
		return array_map( static function ( $r ) { return Inmo_Contratos::get( $r->id ); }, $rows );
	}

	/**
	 * Registra la firma de un firmante (manual hasta conectar el proveedor de firma).
	 * Opcionalmente adjunta el PDF firmado.
	 */
	public static function registrar_firma( $id, $indice, $estado, $archivo = null ) {
		global $wpdb;
		$e = self::get( $id );
		if ( ! $e || 'anulado' === $e->estado_emision || ! isset( $e->firmantes[ $indice ] ) ) {
			return new WP_Error( 'inmo_firma', 'Documento o firmante no válido.' );
		}
		if ( ! in_array( $estado, array( 'pendiente', 'firmado', 'rechazado' ), true ) ) {
			return new WP_Error( 'inmo_firma', 'Estado de firma no válido.' );
		}
		$e->firmantes[ $indice ]['estado'] = $estado;
		$e->firmantes[ $indice ]['fecha']  = 'pendiente' === $estado ? '' : Inmo_Util::ahora();
		$estados = wp_list_pluck( $e->firmantes, 'estado' );
		if ( in_array( 'rechazado', $estados, true ) ) {
			$general = 'rechazado';
		} elseif ( ! in_array( 'pendiente', $estados, true ) ) {
			$general = 'firmado';
		} elseif ( in_array( 'firmado', $estados, true ) ) {
			$general = 'parcial';
		} else {
			$general = 'pendiente';
		}
		if ( $archivo && ! empty( $archivo['name'] ) ) {
			$doc = Inmo_Documentos::guardar_subida( $archivo, $e->propiedad_id, 'firmado', 0, false, $e->tipo );
			if ( is_wp_error( $doc ) ) {
				return $doc;
			}
			$e->firmantes[ $indice ]['documento_id'] = $doc;
		}
		$wpdb->update( self::tabla(), array( 'firmantes' => wp_json_encode( $e->firmantes ), 'estado_firma' => $general, 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $id ) );
		Inmo_Historial::registrar( 'firma', $e->numero . ': ' . $e->firmantes[ $indice ]['rol'] . ' — ' . $estado . ' (' . self::ESTADOS_FIRMA[ $general ] . ')', array( 'propiedad_id' => $e->propiedad_id, 'oportunidad_id' => $e->oportunidad_id ) );
		return true;
	}

	public static function anular( $id, $motivo ) {
		global $wpdb;
		$e = self::get( $id );
		if ( ! $e || 'anulado' === $e->estado_emision ) {
			return new WP_Error( 'inmo_anular', 'Documento no válido.' );
		}
		if ( '' === trim( $motivo ) ) {
			return new WP_Error( 'inmo_anular', 'Indica el motivo de la anulación.' );
		}
		$wpdb->update( self::tabla(), array( 'estado_emision' => 'anulado', 'motivo_anulacion' => sanitize_text_field( $motivo ), 'actualizado' => Inmo_Util::ahora() ), array( 'id' => $id ) );
		Inmo_Historial::registrar( 'emision', $e->numero . ' anulado: ' . $motivo, array( 'propiedad_id' => $e->propiedad_id, 'oportunidad_id' => $e->oportunidad_id ) );
		return true;
	}

	/** Verifica que el PDF guardado no haya cambiado desde su emisión. */
	public static function integro( $e ) {
		$doc = Inmo_Documentos::get( $e->documento_id );
		$abs = $doc ? Inmo_Documentos::ruta_absoluta( $doc ) : '';
		return $abs && hash_file( 'sha256', $abs ) === $e->sha256;
	}

	/* =====================================================================
	 * Formatos en español
	 * =================================================================== */

	public static function fecha_larga( $ymd ) {
		$meses = array( 1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		$d     = date_create_immutable( $ymd, wp_timezone() );
		return $d ? (int) $d->format( 'j' ) . ' de ' . $meses[ (int) $d->format( 'n' ) ] . ' de ' . $d->format( 'Y' ) : '';
	}

	public static function monto_palabras( $monto, $moneda ) {
		if ( '' === (string) $monto || null === $monto ) {
			return '';
		}
		$monto  = (float) $monto;
		$entero = (int) floor( $monto );
		$dec    = (int) round( ( $monto - $entero ) * 100 );
		$txt    = self::numero_palabras( $entero );
		if ( 'UF' === $moneda ) {
			return $txt . ( $dec ? ' coma ' . self::numero_palabras( $dec ) : '' ) . ' unidades de fomento';
		}
		return $txt . ( preg_match( '/(millón|millones)$/u', $txt ) ? ' de pesos' : ' pesos' );
	}

	public static function numero_palabras( $n ) {
		$n = (int) $n;
		if ( 0 === $n ) {
			return 'cero';
		}
		$u = array( '', 'un', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte', 'veintiún', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve' );
		$d = array( 3 => 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa' );
		$c = array( 1 => 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos' );
		$menos_mil = static function ( $x ) use ( $u, $d, $c ) {
			if ( 100 === $x ) {
				return 'cien';
			}
			$s = '';
			if ( $x >= 100 ) {
				$s = $c[ intdiv( $x, 100 ) ];
				$x = $x % 100;
				if ( $x ) {
					$s .= ' ';
				}
			}
			if ( $x >= 30 ) {
				$s .= $d[ intdiv( $x, 10 ) ] . ( $x % 10 ? ' y ' . $u[ $x % 10 ] : '' );
			} elseif ( $x > 0 ) {
				$s .= $u[ $x ];
			}
			return $s;
		};
		$partes = array();
		$mill   = intdiv( $n, 1000000 );
		$resto  = $n % 1000000;
		if ( $mill ) {
			$partes[] = 1 === $mill ? 'un millón' : self::numero_palabras( $mill ) . ' millones';
		}
		$miles = intdiv( $resto, 1000 );
		$resto = $resto % 1000;
		if ( $miles ) {
			$partes[] = 1 === $miles ? 'mil' : $menos_mil( $miles ) . ' mil';
		}
		if ( $resto ) {
			$partes[] = $menos_mil( $resto );
		}
		return implode( ' ', $partes );
	}
}

/**
 * Generación de PDF con Dompdf (LGPL, incluido en vendor/). Reemplazable con el filtro inmo_generar_pdf.
 */
class Inmo_Pdf {

	/** @return string|WP_Error Bytes del PDF. */
	public static function generar( $html ) {
		$externo = apply_filters( 'inmo_generar_pdf', null, $html );
		if ( null !== $externo ) {
			return $externo;
		}
		$auto = INMO_DIR . 'vendor/autoload.php';
		if ( ! file_exists( $auto ) ) {
			return new WP_Error( 'inmo_pdf', 'Falta la librería de PDF (vendor/). Reinstala el plugin.' );
		}
		require_once $auto;
		try {
			$opt = new \Dompdf\Options();
			$opt->set( 'isRemoteEnabled', false );
			$opt->set( 'defaultFont', 'DejaVu Sans' );
			$tmp = trailingslashit( get_temp_dir() ) . 'inmo-dompdf';
			wp_mkdir_p( $tmp );
			$opt->set( 'tempDir', $tmp );
			$opt->set( 'fontCache', $tmp );
			$opt->set( 'chroot', INMO_DIR );
			$pdf = new \Dompdf\Dompdf( $opt );
			$pdf->loadHtml( $html, 'UTF-8' );
			$pdf->setPaper( 'letter' );
			$pdf->render();
			return $pdf->output();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'inmo_pdf', 'No se pudo generar el PDF: ' . $e->getMessage() );
		}
	}
}
