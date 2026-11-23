<?php
/**
 * Valorización automática por comparables.
 *
 * Fuentes de comparables:
 *  1. Catálogo propio: propiedades publicadas (precio pedido) y cerradas (precio acordado de la oportunidad ganada).
 *  2. Comparables de mercado cargados por la corredora (manual o CSV), p. ej. observados en portales.
 *
 * Método (explicable): mismo tipo y operación; comuna (o región si faltan); superficie ±40 %; últimos N meses.
 * Se calcula el valor por m² (venta en UF, arriendo en pesos), se descuenta un margen de negociación a los
 * precios publicados, y se toma la mediana ponderada por similitud, antigüedad y tipo de dato (cierre > publicación).
 * Rango = percentiles ponderados 25–75. La confianza depende de la cantidad de comparables y su dispersión.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Valorizacion {

	const CONFIANZA = array(
		'alta'  => 'Alta',
		'media' => 'Media',
		'baja'  => 'Baja',
	);

	public static function t( $n ) {
		global $wpdb;
		return $wpdb->prefix . 'inmo_' . $n;
	}

	/* =====================================================================
	 * Comparables de mercado (cargados por la corredora)
	 * =================================================================== */

	public static function columnas_csv() {
		return array( 'fecha', 'tipo', 'operacion', 'comuna', 'superficie', 'dormitorios', 'banos', 'estacionamientos', 'precio', 'moneda', 'es_cierre', 'fuente', 'url' );
	}

	/**
	 * @return int|WP_Error
	 */
	public static function agregar_comparable( array $d ) {
		global $wpdb;
		$tipo = sanitize_key( $d['tipo'] ?? '' );
		$op   = sanitize_key( $d['operacion'] ?? '' );
		$op   = array( 'compra' => 'venta', 'arrendar' => 'arriendo' )[ $op ] ?? $op;
		$com  = get_term_by( 'slug', sanitize_title( $d['comuna'] ?? '' ), Inmo_Propiedades::TAX_UBICACION );
		if ( ! $com ) {
			$com = get_term_by( 'name', trim( (string) ( $d['comuna'] ?? '' ) ), Inmo_Propiedades::TAX_UBICACION );
		}
		$sup    = Inmo_Util::parse_numero( $d['superficie'] ?? '' );
		$precio = Inmo_Util::parse_numero( $d['precio'] ?? '' );
		$mon    = strtoupper( trim( (string) ( $d['moneda'] ?? '' ) ) );
		$fecha  = trim( (string) ( $d['fecha'] ?? '' ) );
		$err    = array();
		if ( ! isset( Inmo_Esquema::TIPOS[ $tipo ] ) ) {
			$err[] = 'tipo';
		}
		if ( ! isset( Inmo_Esquema::OPERACIONES[ $op ] ) ) {
			$err[] = 'operación';
		}
		if ( ! $com || ! $com->parent ) {
			$err[] = 'comuna';
		}
		if ( '' === $sup || $sup <= 0 ) {
			$err[] = 'superficie';
		}
		if ( '' === $precio || $precio <= 0 ) {
			$err[] = 'precio';
		}
		if ( ! in_array( $mon, array( 'UF', 'CLP' ), true ) ) {
			$err[] = 'moneda (UF o CLP)';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ) {
			$err[] = 'fecha (AAAA-MM-DD)';
		}
		if ( $err ) {
			return new WP_Error( 'inmo_comp', 'Dato no válido: ' . implode( ', ', $err ) . '.' );
		}
		$wpdb->insert(
			self::t( 'comparables' ),
			array(
				'fecha'            => $fecha,
				'tipo'             => $tipo,
				'operacion'        => $op,
				'comuna_id'        => (int) $com->term_id,
				'superficie'       => $sup,
				'dormitorios'      => '' !== Inmo_Util::parse_numero( $d['dormitorios'] ?? '' ) ? (int) Inmo_Util::parse_numero( $d['dormitorios'] ) : null,
				'banos'            => '' !== Inmo_Util::parse_numero( $d['banos'] ?? '' ) ? (int) Inmo_Util::parse_numero( $d['banos'] ) : null,
				'estacionamientos' => '' !== Inmo_Util::parse_numero( $d['estacionamientos'] ?? '' ) ? (int) Inmo_Util::parse_numero( $d['estacionamientos'] ) : null,
				'precio'           => $precio,
				'moneda'           => $mon,
				'es_cierre'        => in_array( strtolower( (string) ( $d['es_cierre'] ?? '' ) ), array( '1', 'si', 'sí', 'true', 'cierre' ), true ) ? 1 : 0,
				'fuente'           => sanitize_text_field( $d['fuente'] ?? '' ),
				'url'              => esc_url_raw( $d['url'] ?? '' ),
				'user_id'          => get_current_user_id(),
				'creado'           => Inmo_Util::ahora(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Importa un CSV (separador «,» o «;», con encabezados). Devuelve [ importados, errores[] ].
	 */
	public static function importar_csv( $ruta ) {
		$fh = fopen( $ruta, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return array( 0, array( 'No se pudo leer el archivo.' ) );
		}
		$primera = fgets( $fh );
		$sep     = substr_count( $primera, ';' ) > substr_count( $primera, ',' ) ? ';' : ',';
		$cab     = array_map( static function ( $c ) { return sanitize_key( trim( preg_replace( '/^\xEF\xBB\xBF/', '', $c ) ) ); }, str_getcsv( trim( $primera ), $sep ) );
		$faltan  = array_diff( array( 'fecha', 'tipo', 'operacion', 'comuna', 'superficie', 'precio', 'moneda' ), $cab );
		if ( $faltan ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return array( 0, array( 'Faltan columnas: ' . implode( ', ', $faltan ) . '.' ) );
		}
		$ok  = 0;
		$err = array();
		$n   = 1;
		while ( ( $fila = fgetcsv( $fh, 0, $sep ) ) !== false ) {
			$n++;
			if ( count( array_filter( $fila, 'strlen' ) ) === 0 ) {
				continue;
			}
			$d = array_combine( $cab, array_pad( array_slice( $fila, 0, count( $cab ) ), count( $cab ), '' ) );
			$r = self::agregar_comparable( $d );
			if ( is_wp_error( $r ) ) {
				$err[] = 'Fila ' . $n . ': ' . $r->get_error_message();
			} else {
				$ok++;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( $ok, $err );
	}

	public static function listar_comparables( $limite = 200 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'comparables' ) . ' ORDER BY fecha DESC, id DESC LIMIT %d', $limite ) ); // phpcs:ignore
	}

	public static function borrar_comparable( $id ) {
		global $wpdb;
		$wpdb->delete( self::t( 'comparables' ), array( 'id' => (int) $id ) );
	}

	/* =====================================================================
	 * Universo de comparables normalizado
	 * =================================================================== */

	/** Convierte un precio a la unidad de análisis: venta → UF; arriendo → pesos. */
	private static function a_unidad( $precio, $moneda, $op, $fecha ) {
		$uf = Inmo_Indicadores::uf_para( $fecha )['valor'];
		if ( 'venta' === $op ) {
			return 'UF' === $moneda ? (float) $precio : (float) $precio / $uf;
		}
		return 'CLP' === $moneda ? (float) $precio : (float) $precio * $uf;
	}

	/** Superficie de referencia según tipo. */
	public static function superficie_de( $tipo, array $m ) {
		if ( 'terreno' === $tipo ) {
			return (float) ( $m['sup_terreno'] ?? 0 );
		}
		if ( 'casa' === $tipo && ! empty( $m['sup_construida'] ) ) {
			return (float) $m['sup_construida'];
		}
		return (float) ( $m['sup_util'] ?? ( $m['sup_construida'] ?? 0 ) );
	}

	/**
	 * Comparables candidatos (catálogo propio + mercado), normalizados.
	 *
	 * @return array de [ 'origen','id','codigo','fecha','comuna_id','region_id','superficie','dormitorios','estacionamientos','valor','valor_m2','es_cierre','fuente','url' ]
	 */
	public static function universo( $tipo, $op, $excluir_pid = 0 ) {
		global $wpdb;
		$meses  = (int) Inmo_Ajustes::get( 'valorizacion_meses', 24 );
		$desde  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . $meses . ' months' ) );
		$out    = array();
		// 1. Catálogo propio.
		$ids = get_posts(
			array(
				'post_type'      => INMO_CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'post__not_in'   => $excluir_pid ? array( (int) $excluir_pid ) : array(),
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'taxonomy' => Inmo_Propiedades::TAX_TIPO, 'field' => 'slug', 'terms' => $tipo ),
					array( 'taxonomy' => Inmo_Propiedades::TAX_OPERACION, 'field' => 'slug', 'terms' => $op ),
				),
			)
		);
		foreach ( $ids as $pid ) {
			$estado = get_post_meta( $pid, '_inmo_estado', true );
			if ( ! in_array( $estado, array( 'disponible', 'reservada', 'vendida', 'arrendada' ), true ) ) {
				continue;
			}
			$m = array();
			foreach ( array( 'sup_util', 'sup_construida', 'sup_terreno', 'dormitorios', 'estacionamientos' ) as $k ) {
				$m[ $k ] = get_post_meta( $pid, '_inmo_' . $k, true );
			}
			$sup = self::superficie_de( $tipo, $m );
			$com = Inmo_Propiedades::comuna( $pid );
			if ( $sup <= 0 || ! $com ) {
				continue;
			}
			$cierre = in_array( $estado, array( 'vendida', 'arrendada' ), true );
			$precio = get_post_meta( $pid, '_inmo_precio', true );
			$moneda = get_post_meta( $pid, '_inmo_moneda', true );
			$fecha  = get_post_field( 'post_modified', $pid );
			if ( $cierre ) {
				$o = $wpdb->get_row( $wpdb->prepare( 'SELECT condiciones, cerrado FROM ' . Inmo_Oportunidades::tabla() . " WHERE propiedad_id = %d AND etapa = 'ganada' ORDER BY id DESC LIMIT 1", $pid ) ); // phpcs:ignore
				if ( $o ) {
					$cond = (array) json_decode( (string) $o->condiciones, true );
					if ( ! empty( $cond['precio_acordado'] ) ) {
						$precio = $cond['precio_acordado'];
						$moneda = ! empty( $cond['moneda_acordada'] ) ? $cond['moneda_acordada'] : $moneda;
					}
					$fecha = $o->cerrado ? $o->cerrado : $fecha;
				}
			}
			$fecha = substr( (string) $fecha, 0, 10 );
			if ( '' === (string) $precio || $fecha < $desde ) {
				continue;
			}
			$valor = self::a_unidad( $precio, $moneda, $op, $fecha );
			$out[] = array(
				'origen'           => 'catalogo',
				'id'               => $pid,
				'codigo'           => get_post_meta( $pid, '_inmo_codigo', true ),
				'fecha'            => $fecha,
				'comuna_id'        => (int) $com->term_id,
				'region_id'        => (int) $com->parent,
				'superficie'       => $sup,
				'dormitorios'      => '' !== (string) $m['dormitorios'] ? (int) $m['dormitorios'] : null,
				'estacionamientos' => '' !== (string) $m['estacionamientos'] ? (int) $m['estacionamientos'] : null,
				'valor'            => $valor,
				'es_cierre'        => $cierre,
				'fuente'           => $cierre ? 'Cierre propio' : 'Publicación propia',
				'url'              => '',
			);
		}
		// 2. Comparables de mercado.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'comparables' ) . ' WHERE tipo = %s AND operacion = %s AND fecha >= %s', $tipo, $op, $desde ) ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$com   = get_term( (int) $r->comuna_id, Inmo_Propiedades::TAX_UBICACION );
			$out[] = array(
				'origen'           => 'mercado',
				'id'               => (int) $r->id,
				'codigo'           => '',
				'fecha'            => $r->fecha,
				'comuna_id'        => (int) $r->comuna_id,
				'region_id'        => ( $com && ! is_wp_error( $com ) ) ? (int) $com->parent : 0,
				'superficie'       => (float) $r->superficie,
				'dormitorios'      => null !== $r->dormitorios ? (int) $r->dormitorios : null,
				'estacionamientos' => null !== $r->estacionamientos ? (int) $r->estacionamientos : null,
				'valor'            => self::a_unidad( $r->precio, $r->moneda, $op, $r->fecha ),
				'es_cierre'        => (bool) $r->es_cierre,
				'fuente'           => $r->fuente ? $r->fuente : 'Mercado',
				'url'              => $r->url,
			);
		}
		return $out;
	}

	/* =====================================================================
	 * Estimación
	 * =================================================================== */

	/**
	 * Estima el valor.
	 *
	 * @param array $s [ tipo, operacion, comuna_id, superficie, dormitorios?, estacionamientos?, excluir_pid? ]
	 * @return array [ suficiente, valor, min, max, moneda, valor_m2, confianza, n, ambito, comparables[], explicacion ]
	 */
	public static function estimar( array $s ) {
		$tipo = $s['tipo'];
		$op   = $s['operacion'];
		$sup  = (float) $s['superficie'];
		$com  = get_term( (int) $s['comuna_id'], Inmo_Propiedades::TAX_UBICACION );
		$res  = array( 'suficiente' => false, 'moneda' => 'venta' === $op ? 'UF' : 'CLP', 'n' => 0, 'comparables' => array(), 'operacion' => $op );
		if ( ! isset( Inmo_Esquema::TIPOS[ $tipo ], Inmo_Esquema::OPERACIONES[ $op ] ) || $sup <= 0 || ! $com || is_wp_error( $com ) ) {
			$res['motivo'] = 'Faltan tipo, operación, comuna o superficie.';
			return $res;
		}
		$min_n   = max( 1, (int) Inmo_Ajustes::get( 'valorizacion_min', 3 ) );
		$desc    = (float) Inmo_Ajustes::get( 'valorizacion_descuento', 3 ) / 100;
		$meses   = (int) Inmo_Ajustes::get( 'valorizacion_meses', 24 );
		$todos   = self::universo( $tipo, $op, (int) ( $s['excluir_pid'] ?? 0 ) );
		$cerca   = array_filter( $todos, static function ( $c ) use ( $sup ) { return $c['superficie'] >= $sup * 0.6 && $c['superficie'] <= $sup * 1.4; } );
		$comuna  = array_filter( $cerca, static function ( $c ) use ( $com ) { return $c['comuna_id'] === (int) $com->term_id; } );
		$ambito  = 'comuna';
		$usar    = $comuna;
		if ( count( $comuna ) < $min_n ) {
			$usar   = array_filter( $cerca, static function ( $c ) use ( $com ) { return $c['region_id'] && $c['region_id'] === (int) $com->parent; } );
			$ambito = 'region';
		}
		if ( count( $usar ) < $min_n ) {
			$res['n']      = count( $usar );
			$res['motivo'] = 'No hay suficientes comparables (' . count( $usar ) . ' de ' . $min_n . ' necesarios).';
			return $res;
		}
		$hoy   = strtotime( current_time( 'Y-m-d' ) );
		$items = array();
		foreach ( $usar as $c ) {
			$v_m2 = $c['valor'] * ( $c['es_cierre'] ? 1 : ( 1 - $desc ) ) / $c['superficie'];
			$sim  = 1 - min( 0.5, abs( $c['superficie'] - $sup ) / $sup );
			if ( isset( $s['dormitorios'] ) && '' !== (string) $s['dormitorios'] && null !== $c['dormitorios'] ) {
				$sim *= ( (int) $s['dormitorios'] === $c['dormitorios'] ) ? 1 : 0.85;
			}
			if ( 'region' === $ambito && $c['comuna_id'] !== (int) $com->term_id ) {
				$sim *= 0.7;
			}
			$meses_ant = max( 0, ( $hoy - strtotime( $c['fecha'] ) ) / ( 30 * DAY_IN_SECONDS ) );
			$rec       = 1 - 0.5 * min( 1, $meses_ant / max( 1, $meses ) );
			$peso      = $sim * $rec * ( $c['es_cierre'] ? 1.0 : 0.8 );
			$items[]   = $c + array( 'valor_m2' => $v_m2, 'peso' => round( $peso, 4 ) );
		}
		usort( $items, static function ( $a, $b ) { return $a['valor_m2'] <=> $b['valor_m2']; } );
		$p25 = self::percentil( $items, 0.25 );
		$p50 = self::percentil( $items, 0.50 );
		$p75 = self::percentil( $items, 0.75 );

		// Ajuste por estacionamientos respecto de la mediana de los comparables.
		$ajuste = 0;
		if ( isset( $s['estacionamientos'] ) && '' !== (string) $s['estacionamientos'] ) {
			$estac = array_filter( array_column( $items, 'estacionamientos' ), static function ( $x ) { return null !== $x; } );
			if ( $estac ) {
				sort( $estac );
				$med    = $estac[ intdiv( count( $estac ), 2 ) ];
				$unit   = 'venta' === $op ? (float) Inmo_Ajustes::get( 'ajuste_estac_venta', 300 ) : (float) Inmo_Ajustes::get( 'ajuste_estac_arriendo', 30000 );
				$ajuste = ( (int) $s['estacionamientos'] - $med ) * $unit;
			}
		}
		$red = static function ( $x ) use ( $op ) {
			return 'venta' === $op ? round( $x / 10 ) * 10 : round( $x / 5000 ) * 5000; // UF a la decena; pesos a $5.000.
		};
		$valores = array_column( $items, 'valor_m2' );
		$media   = array_sum( $valores ) / count( $valores );
		$sd      = sqrt( array_sum( array_map( static function ( $x ) use ( $media ) { return ( $x - $media ) ** 2; }, $valores ) ) / count( $valores ) );
		$cv      = $media > 0 ? $sd / $media : 1;
		$n       = count( $items );
		$conf    = ( $n >= 8 && $cv <= 0.15 && 'comuna' === $ambito ) ? 'alta' : ( ( $n >= 4 && $cv <= 0.30 ) ? 'media' : 'baja' );

		return array(
			'suficiente'  => true,
			'operacion'   => $op,
			'moneda'      => 'venta' === $op ? 'UF' : 'CLP',
			'valor'       => $red( $p50 * $sup + $ajuste ),
			'min'         => $red( $p25 * $sup + $ajuste ),
			'max'         => $red( $p75 * $sup + $ajuste ),
			'valor_m2'    => round( $p50, 'venta' === $op ? 2 : 0 ),
			'ajuste'      => $ajuste,
			'confianza'   => $conf,
			'dispersion'  => round( $cv * 100, 1 ),
			'n'           => $n,
			'ambito'      => 'comuna' === $ambito ? $com->name : 'región (' . ( get_term( $com->parent )->name ?? '' ) . ')',
			'comparables' => $items,
			'explicacion' => sprintf(
				'%d comparables de %s en %s, superficie entre %s y %s m², últimos %d meses. Valor por m² ponderado: %s. Los precios publicados se descuentan %s%% por negociación.',
				$n,
				strtolower( Inmo_Esquema::TIPOS[ $tipo ] ) . ( 'venta' === $op ? ' en venta' : ' en arriendo' ),
				'comuna' === $ambito ? $com->name : 'la región',
				number_format( $sup * 0.6, 0, ',', '.' ),
				number_format( $sup * 1.4, 0, ',', '.' ),
				$meses,
				Inmo_Util::formatear_monto( round( $p50, 'venta' === $op ? 2 : 0 ), 'venta' === $op ? 'UF' : 'CLP' ),
				rtrim( rtrim( number_format( $desc * 100, 1, ',', '' ), '0' ), ',' )
			),
		);
	}

	/** Percentil ponderado sobre una lista ordenada por valor_m2. */
	private static function percentil( array $items, $p ) {
		$total = array_sum( array_column( $items, 'peso' ) );
		if ( $total <= 0 ) {
			return $items[ intdiv( count( $items ), 2 ) ]['valor_m2'];
		}
		$acum = 0;
		foreach ( $items as $i ) {
			$acum += $i['peso'];
			if ( $acum / $total >= $p ) {
				return $i['valor_m2'];
			}
		}
		return end( $items )['valor_m2'];
	}

	/** Estimación para una propiedad del catálogo (excluyéndola de sus propios comparables). */
	public static function de_propiedad( $pid ) {
		$tipo = Inmo_Propiedades::termino( $pid, Inmo_Propiedades::TAX_TIPO );
		$op   = Inmo_Propiedades::termino( $pid, Inmo_Propiedades::TAX_OPERACION );
		$com  = Inmo_Propiedades::comuna( $pid );
		$m    = array();
		foreach ( array( 'sup_util', 'sup_construida', 'sup_terreno', 'dormitorios', 'estacionamientos' ) as $k ) {
			$m[ $k ] = get_post_meta( $pid, '_inmo_' . $k, true );
		}
		return self::estimar(
			array(
				'tipo'             => $tipo,
				'operacion'        => $op,
				'comuna_id'        => $com ? $com->term_id : 0,
				'superficie'       => self::superficie_de( $tipo, $m ),
				'dormitorios'      => $m['dormitorios'],
				'estacionamientos' => $m['estacionamientos'],
				'excluir_pid'      => $pid,
			)
		);
	}

	/** Guarda una valorización (trazabilidad). */
	public static function registrar( $r, $propiedad_id = 0, $consulta_id = 0, $entrada = array() ) {
		global $wpdb;
		$resumen = $r;
		if ( ! empty( $resumen['comparables'] ) ) {
			$resumen['comparables'] = array_map(
				static function ( $c ) {
					return array_intersect_key( $c, array_flip( array( 'origen', 'id', 'codigo', 'fecha', 'superficie', 'valor', 'valor_m2', 'peso', 'es_cierre', 'fuente' ) ) );
				},
				$resumen['comparables']
			);
		}
		$wpdb->insert(
			self::t( 'valorizaciones' ),
			array(
				'propiedad_id' => (int) $propiedad_id,
				'consulta_id'  => (int) $consulta_id,
				'operacion'    => $r['operacion'] ?? '',
				'valor'        => $r['suficiente'] ? $r['valor'] : null,
				'minimo'       => $r['suficiente'] ? $r['min'] : null,
				'maximo'       => $r['suficiente'] ? $r['max'] : null,
				'moneda'       => $r['moneda'],
				'confianza'    => $r['suficiente'] ? $r['confianza'] : '',
				'n'            => (int) $r['n'],
				'entrada'      => wp_json_encode( $entrada ),
				'resultado'    => wp_json_encode( $resumen ),
				'user_id'      => get_current_user_id(),
				'creado'       => Inmo_Util::ahora(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function de_consulta( $consulta_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'valorizaciones' ) . ' WHERE consulta_id = %d ORDER BY id', $consulta_id ) ); // phpcs:ignore
	}

	public static function texto_rango( $r ) {
		return Inmo_Util::formatear_monto( $r['min'], $r['moneda'] ) . ' – ' . Inmo_Util::formatear_monto( $r['max'], $r['moneda'] );
	}
}
