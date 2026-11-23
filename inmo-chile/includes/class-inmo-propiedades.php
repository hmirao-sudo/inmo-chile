<?php
/**
 * Propiedades: tipo de contenido, taxonomías, código estable, estado comercial ↔ estado de publicación.
 *
 * Datos públicos de la ficha → postmeta (_inmo_*), consultables y filtrables.
 * Operación, tipo y ubicación (región > comuna) → taxonomías (filtros rápidos y URLs compartibles).
 * Datos privados → tabla inmo_propiedad_privado (ver Inmo_Privado).
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Propiedades {

	const TAX_OPERACION = 'inmo_operacion';
	const TAX_TIPO      = 'inmo_tipo';
	const TAX_UBICACION = 'inmo_ubicacion';

	/** Evita que el filtro de estado se aplique a cambios programáticos. */
	private static $sincronizando = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'forzar_estado_publicacion' ), 20, 2 );
		add_action( 'save_post_' . INMO_CPT, array( __CLASS__, 'asegurar_codigo' ), 5, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'al_borrar' ) );
		// Una propiedad vendida/arrendada queda privada: sin el prefijo «Privado:» en contratos, liquidaciones y portal.
		add_filter( 'private_title_format', array( __CLASS__, 'titulo_privado' ), 10, 2 );
	}

	public static function titulo_privado( $formato, $post ) {
		return ( $post && INMO_CPT === get_post_type( $post ) ) ? '%s' : $formato;
	}

	public static function registrar() {
		register_post_type(
			INMO_CPT,
			array(
				'labels'              => array(
					'name'               => 'Propiedades',
					'singular_name'      => 'Propiedad',
					'add_new'            => 'Nueva captación',
					'add_new_item'       => 'Nueva captación',
					'edit_item'          => 'Ficha interna de la propiedad',
					'new_item'           => 'Nueva propiedad',
					'view_item'          => 'Ver ficha pública',
					'search_items'       => 'Buscar propiedades',
					'not_found'          => 'No hay propiedades.',
					'not_found_in_trash' => 'No hay propiedades en la papelera.',
					'all_items'          => 'Propiedades',
					'menu_name'          => 'Propiedades',
				),
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => 'inmo',
				'show_in_rest'        => false, // Sin API REST pública: la vitrina se sirve desde el tema.
				'has_archive'         => 'propiedades',
				'rewrite'             => array( 'slug' => 'propiedades', 'with_front' => false ),
				'supports'            => array( 'title', 'editor', 'author', 'thumbnail' ),
				'capability_type'     => array( 'inmo_propiedad', 'inmo_propiedades' ),
				'map_meta_cap'        => true,
				'exclude_from_search' => false,
				'menu_icon'           => 'dashicons-building',
			)
		);

		$comun = array(
			'public'            => false,
			'publicly_queryable' => false,
			'show_ui'           => true,
			'show_in_menu'      => false,
			'show_in_rest'      => false,
			'query_var'         => false,
			'rewrite'           => false,
			'show_admin_column' => false,
			'meta_box_cb'       => false,
			'capabilities'      => array(
				'manage_terms' => 'inmo_ajustes',
				'edit_terms'   => 'inmo_ajustes',
				'delete_terms' => 'inmo_ajustes',
				'assign_terms' => 'edit_inmo_propiedades',
			),
		);
		register_taxonomy( self::TAX_OPERACION, INMO_CPT, array_merge( $comun, array( 'label' => 'Operación', 'hierarchical' => false ) ) );
		register_taxonomy( self::TAX_TIPO, INMO_CPT, array_merge( $comun, array( 'label' => 'Tipo de propiedad', 'hierarchical' => false ) ) );
		register_taxonomy( self::TAX_UBICACION, INMO_CPT, array_merge( $comun, array( 'label' => 'Regiones y comunas', 'hierarchical' => true, 'show_in_menu' => true ) ) );

		foreach ( array_merge( array_keys( Inmo_Esquema::campos() ), array( 'precio', 'moneda', 'precio_clp', 'codigo', 'estado', 'visibilidad', 'destacado', 'sector', 'precision', 'lat_pub', 'lng_pub', 'equipamiento', 'galeria' ) ) as $k ) {
			register_post_meta( INMO_CPT, '_inmo_' . $k, array( 'show_in_rest' => false, 'single' => true, 'auth_callback' => static function () { return current_user_can( 'edit_inmo_propiedades' ); } ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Código estable
	 * ------------------------------------------------------------------- */

	/** Siguiente número de la secuencia (atómico en MySQL). */
	public static function siguiente_numero() {
		global $wpdb;
		if ( false === get_option( 'inmo_codigo_seq', false ) ) {
			add_option( 'inmo_codigo_seq', 0, '', false );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( option_value + 1 ) WHERE option_name = %s", 'inmo_codigo_seq' ) );
		$n = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		wp_cache_delete( 'inmo_codigo_seq', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return $n;
	}

	public static function asegurar_codigo( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === get_post_status( $post_id ) ) {
			return;
		}
		if ( get_post_meta( $post_id, '_inmo_codigo', true ) ) {
			return;
		}
		$prefijo = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) Inmo_Ajustes::get( 'codigo_prefijo', 'CP' ) ) );
		$codigo  = ( $prefijo ? $prefijo : 'CP' ) . '-' . str_pad( (string) self::siguiente_numero(), 4, '0', STR_PAD_LEFT );
		update_post_meta( $post_id, '_inmo_codigo', $codigo );
		if ( ! get_post_meta( $post_id, '_inmo_estado', true ) ) {
			update_post_meta( $post_id, '_inmo_estado', 'borrador' );
		}
	}

	public static function por_codigo( $codigo ) {
		$q = get_posts(
			array(
				'post_type'      => INMO_CPT,
				'post_status'    => 'any',
				'meta_key'       => '_inmo_codigo', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => strtoupper( sanitize_text_field( $codigo ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return $q ? (int) $q[0] : 0;
	}

	/* ---------------------------------------------------------------------
	 * Estado comercial, visibilidad y estado de publicación
	 * ------------------------------------------------------------------- */

	/** Estado de publicación WordPress que corresponde a un estado comercial + visibilidad. */
	public static function post_status_para( $estado, $visibilidad ) {
		switch ( $estado ) {
			case 'borrador':
				return 'draft';
			case 'en_revision':
				return 'pending';
			case 'disponible':
			case 'reservada':
				return 'publica' === $visibilidad ? 'publish' : 'private';
			default:
				return 'private'; // vendida, arrendada, archivada.
		}
	}

	/**
	 * Valida una transición de estado. Devuelve el estado efectivo permitido.
	 * Sin la capacidad inmo_aprobar, un corredor no puede publicar una captación: queda "en revisión".
	 */
	public static function estado_permitido( $post_id, $nuevo, $user_id = null ) {
		$user_id  = $user_id ? $user_id : get_current_user_id();
		$anterior = $post_id ? get_post_meta( $post_id, '_inmo_estado', true ) : '';
		$anterior = $anterior ? $anterior : 'borrador';
		if ( ! isset( Inmo_Esquema::ESTADOS[ $nuevo ] ) ) {
			return $anterior;
		}
		$ya_aprobada = ! in_array( $anterior, array( 'borrador', 'en_revision' ), true );
		if ( in_array( $nuevo, Inmo_Esquema::ESTADOS_APROBADOS, true ) && ! $ya_aprobada && ! user_can( $user_id, 'inmo_aprobar' ) ) {
			return 'en_revision';
		}
		return $nuevo;
	}

	/** Requisitos para aprobar/publicar. Devuelve lista de faltantes (vacía = OK). */
	public static function faltantes_para_publicar( $post_id ) {
		$f = array();
		if ( ! self::termino( $post_id, self::TAX_TIPO ) ) {
			$f[] = 'Tipo de propiedad';
		}
		if ( ! self::termino( $post_id, self::TAX_OPERACION ) ) {
			$f[] = 'Operación';
		}
		if ( ! self::comuna( $post_id ) ) {
			$f[] = 'Comuna';
		}
		if ( '' === (string) get_post_meta( $post_id, '_inmo_precio', true ) ) {
			$f[] = 'Precio';
		}
		if ( ! self::galeria( $post_id ) ) {
			$f[] = 'Al menos una foto autorizada';
		}
		return $f;
	}

	/**
	 * Filtro wp_insert_post_data: el estado de publicación SIEMPRE se deriva del estado comercial y la visibilidad.
	 */
	public static function forzar_estado_publicacion( $data, $postarr ) {
		if ( INMO_CPT !== $data['post_type'] || self::$sincronizando || in_array( $data['post_status'], array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return $data;
		}
		$post_id = ! empty( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- el nonce lo valida Inmo_Admin_Propiedad::guardar; aquí solo se deriva el estado.
		$solicitado = isset( $_POST['inmo_estado'] ) && isset( $_POST['inmo_nonce'] ) ? sanitize_key( wp_unslash( $_POST['inmo_estado'] ) ) : '';
		$vis        = isset( $_POST['inmo_visibilidad'] ) && isset( $_POST['inmo_nonce'] ) ? sanitize_key( wp_unslash( $_POST['inmo_visibilidad'] ) ) : '';
		// phpcs:enable
		$estado = $solicitado ? self::estado_permitido( $post_id, $solicitado ) : ( $post_id ? get_post_meta( $post_id, '_inmo_estado', true ) : '' );
		$estado = $estado ? $estado : 'borrador';
		if ( ! $vis ) {
			$vis = $post_id ? get_post_meta( $post_id, '_inmo_visibilidad', true ) : '';
		}
		$vis = isset( Inmo_Esquema::VISIBILIDAD[ $vis ] ) ? $vis : 'publica';
		$data['post_status'] = self::post_status_para( $estado, $vis );
		if ( 'publish' === $data['post_status'] && empty( $data['post_name'] ) && $post_id ) {
			$data['post_name'] = self::slug_para( $post_id, wp_unslash( $data['post_title'] ) );
		}
		return $data;
	}

	/**
	 * Cambia el estado comercial (API programática). Respeta permisos y requisitos.
	 *
	 * @return string|WP_Error Estado efectivo.
	 */
	public static function cambiar_estado( $post_id, $nuevo, $visibilidad = null, $user_id = null ) {
		$anterior = get_post_meta( $post_id, '_inmo_estado', true );
		$efectivo = self::estado_permitido( $post_id, $nuevo, $user_id );
		if ( in_array( $efectivo, Inmo_Esquema::ESTADOS_PUBLICABLES, true ) ) {
			$falt = self::faltantes_para_publicar( $post_id );
			if ( $falt ) {
				return new WP_Error( 'inmo_incompleta', 'Falta completar: ' . implode( ', ', $falt ) . '.' );
			}
		}
		$op = self::termino( $post_id, self::TAX_OPERACION );
		if ( ( 'vendida' === $efectivo && 'arriendo' === $op ) || ( 'arrendada' === $efectivo && 'venta' === $op ) ) {
			return new WP_Error( 'inmo_estado_op', 'El estado no corresponde a la operación.' );
		}
		update_post_meta( $post_id, '_inmo_estado', $efectivo );
		if ( $visibilidad && isset( Inmo_Esquema::VISIBILIDAD[ $visibilidad ] ) ) {
			update_post_meta( $post_id, '_inmo_visibilidad', $visibilidad );
		}
		self::sincronizar_post_status( $post_id );
		if ( $anterior !== $efectivo ) {
			Inmo_Historial::registrar( 'estado', ( $anterior ? Inmo_Esquema::ESTADOS[ $anterior ] : '—' ) . ' → ' . Inmo_Esquema::ESTADOS[ $efectivo ], array( 'propiedad_id' => $post_id ) );
			do_action( 'inmo_estado_cambiado', $post_id, $efectivo, $anterior );
		}
		return $efectivo;
	}

	public static function sincronizar_post_status( $post_id ) {
		$estado = get_post_meta( $post_id, '_inmo_estado', true );
		$vis    = get_post_meta( $post_id, '_inmo_visibilidad', true );
		$status = self::post_status_para( $estado ? $estado : 'borrador', $vis ? $vis : 'publica' );
		if ( get_post_status( $post_id ) !== $status ) {
			$upd = array( 'ID' => $post_id, 'post_status' => $status );
			if ( 'publish' === $status && ! get_post_field( 'post_name', $post_id ) ) {
				$upd['post_name'] = self::slug_para( $post_id, get_the_title( $post_id ) );
			}
			self::$sincronizando = true;
			wp_update_post( $upd );
			self::$sincronizando = false;
		}
	}

	/** Slug estable: título + código (p. ej. departamento-en-arriendo-en-nunoa-cp-0003). */
	public static function slug_para( $post_id, $titulo ) {
		return sanitize_title( $titulo . ' ' . get_post_meta( $post_id, '_inmo_codigo', true ) );
	}

	/* ---------------------------------------------------------------------
	 * Datos públicos
	 * ------------------------------------------------------------------- */

	public static function termino( $post_id, $tax ) {
		$t = get_the_terms( $post_id, $tax );
		return ( $t && ! is_wp_error( $t ) ) ? $t[0]->slug : '';
	}

	/** Término de comuna (hijo de una región). */
	public static function comuna( $post_id ) {
		$t = get_the_terms( $post_id, self::TAX_UBICACION );
		if ( ! $t || is_wp_error( $t ) ) {
			return null;
		}
		foreach ( $t as $term ) {
			if ( $term->parent ) {
				return $term;
			}
		}
		return $t[0];
	}

	public static function asignar_comuna( $post_id, $term_id ) {
		$term = get_term( (int) $term_id, self::TAX_UBICACION );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$ids = array( (int) $term->term_id );
		if ( $term->parent ) {
			$ids[] = (int) $term->parent;
		}
		wp_set_object_terms( $post_id, $ids, self::TAX_UBICACION );
	}

	public static function comuna_por_nombre( $nombre ) {
		$t = get_term_by( 'slug', sanitize_title( $nombre ), self::TAX_UBICACION );
		return $t ? (int) $t->term_id : 0;
	}

	public static function galeria( $post_id ) {
		$g = get_post_meta( $post_id, '_inmo_galeria', true );
		$g = is_array( $g ) ? array_map( 'intval', $g ) : array();
		return array_values( array_filter( $g, static function ( $id ) { return 'attachment' === get_post_type( $id ); } ) );
	}

	public static function guardar_galeria( $post_id, array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		update_post_meta( $post_id, '_inmo_galeria', $ids );
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_inmo_autorizada', 1 );
		}
		if ( $ids ) {
			set_post_thumbnail( $post_id, $ids[0] );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}

	/**
	 * Guarda datos públicos de la ficha desde un arreglo (admin, formulario propietario, datos de prueba).
	 * Solo acepta campos aplicables al tipo y operación.
	 */
	public static function guardar_publicos( $post_id, array $d ) {
		$tipo = isset( $d['tipo'] ) && isset( Inmo_Esquema::TIPOS[ $d['tipo'] ] ) ? $d['tipo'] : self::termino( $post_id, self::TAX_TIPO );
		$op   = isset( $d['operacion'] ) && isset( Inmo_Esquema::OPERACIONES[ $d['operacion'] ] ) ? $d['operacion'] : self::termino( $post_id, self::TAX_OPERACION );
		if ( isset( $d['tipo'] ) && $tipo ) {
			wp_set_object_terms( $post_id, $tipo, self::TAX_TIPO );
		}
		if ( isset( $d['operacion'] ) && $op ) {
			wp_set_object_terms( $post_id, $op, self::TAX_OPERACION );
		}
		if ( ! empty( $d['comuna_id'] ) ) {
			self::asignar_comuna( $post_id, $d['comuna_id'] );
		}

		$precio_antes = get_post_meta( $post_id, '_inmo_precio', true ) . ' ' . get_post_meta( $post_id, '_inmo_moneda', true );
		if ( array_key_exists( 'precio', $d ) ) {
			$p = Inmo_Util::parse_numero( $d['precio'] );
			update_post_meta( $post_id, '_inmo_precio', '' === $p ? '' : $p );
		}
		if ( isset( $d['moneda'] ) && isset( Inmo_Esquema::MONEDAS[ $d['moneda'] ] ) ) {
			update_post_meta( $post_id, '_inmo_moneda', $d['moneda'] );
		} elseif ( ! get_post_meta( $post_id, '_inmo_moneda', true ) && $op ) {
			update_post_meta( $post_id, '_inmo_moneda', Inmo_Esquema::moneda_defecto( $op ) );
		}
		$precio_despues = get_post_meta( $post_id, '_inmo_precio', true ) . ' ' . get_post_meta( $post_id, '_inmo_moneda', true );
		if ( trim( $precio_antes ) && $precio_antes !== $precio_despues ) {
			Inmo_Historial::registrar( 'precio', 'Precio: ' . self::precio_texto( $precio_antes ) . ' → ' . self::precio_texto( $precio_despues ), array( 'propiedad_id' => $post_id ) );
			do_action( 'inmo_precio_cambiado', $post_id, $precio_antes, $precio_despues );
		}

		foreach ( Inmo_Esquema::campos() as $k => $c ) {
			if ( ! array_key_exists( $k, $d ) ) {
				continue;
			}
			if ( ! Inmo_Esquema::aplica( $c, $tipo, $op ) ) {
				delete_post_meta( $post_id, '_inmo_' . $k );
				continue;
			}
			$v = $d[ $k ];
			if ( 'numero' === $c['tipo'] ) {
				$v = Inmo_Util::parse_numero( $v );
			} elseif ( 'select' === $c['tipo'] ) {
				$v = isset( $c['opciones'][ $v ] ) ? $v : '';
			} elseif ( 'fecha' === $c['tipo'] ) {
				$v = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? $v : '';
			} else {
				$v = sanitize_text_field( $v );
			}
			if ( '' === $v ) {
				delete_post_meta( $post_id, '_inmo_' . $k );
			} else {
				update_post_meta( $post_id, '_inmo_' . $k, $v );
			}
		}
		if ( array_key_exists( 'equipamiento', $d ) ) {
			$validos = array_keys( Inmo_Esquema::equipamiento( $tipo ) );
			update_post_meta( $post_id, '_inmo_equipamiento', array_values( array_intersect( (array) $d['equipamiento'], $validos ) ) );
		}
		if ( array_key_exists( 'sector', $d ) ) {
			update_post_meta( $post_id, '_inmo_sector', sanitize_text_field( $d['sector'] ) );
		}
		if ( isset( $d['precision'] ) && isset( Inmo_Esquema::PRECISION[ $d['precision'] ] ) ) {
			update_post_meta( $post_id, '_inmo_precision', $d['precision'] );
		}
		if ( array_key_exists( 'destacado', $d ) ) {
			update_post_meta( $post_id, '_inmo_destacado', $d['destacado'] ? 1 : 0 );
		}
		if ( isset( $d['visibilidad'] ) && isset( Inmo_Esquema::VISIBILIDAD[ $d['visibilidad'] ] ) ) {
			update_post_meta( $post_id, '_inmo_visibilidad', $d['visibilidad'] );
		}
		self::actualizar_precio_referencia( $post_id );
		self::actualizar_ubicacion_publica( $post_id );
	}

	private static function precio_texto( $s ) {
		$p = explode( ' ', trim( $s ) );
		return Inmo_Util::formatear_monto( $p[0], isset( $p[1] ) ? $p[1] : '' );
	}

	/** Precio normalizado en CLP (solo para filtrar y ordenar; nunca reemplaza el precio original). */
	public static function actualizar_precio_referencia( $post_id ) {
		$p = get_post_meta( $post_id, '_inmo_precio', true );
		if ( '' === $p ) {
			delete_post_meta( $post_id, '_inmo_precio_clp' );
			return;
		}
		$clp = 'UF' === get_post_meta( $post_id, '_inmo_moneda', true ) ? (float) $p * Inmo_Ajustes::uf() : (float) $p;
		update_post_meta( $post_id, '_inmo_precio_clp', round( $clp ) );
	}

	public static function recalcular_precios_referencia() {
		$ids = get_posts( array( 'post_type' => INMO_CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		foreach ( $ids as $id ) {
			self::actualizar_precio_referencia( $id );
		}
	}

	/**
	 * Calcula la ubicación pública a partir de la exacta (privada) y la precisión elegida.
	 * "aproximada" desplaza el punto de forma determinística (~150–400 m) para no revelar la dirección.
	 */
	public static function actualizar_ubicacion_publica( $post_id ) {
		$priv = Inmo_Privado::get( $post_id );
		$prec = get_post_meta( $post_id, '_inmo_precision', true );
		$prec = $prec ? $prec : Inmo_Ajustes::get( 'precision_defecto', 'aproximada' );
		if ( '' === (string) $priv['lat'] || '' === (string) $priv['lng'] || 'comuna' === $prec ) {
			delete_post_meta( $post_id, '_inmo_lat_pub' );
			delete_post_meta( $post_id, '_inmo_lng_pub' );
			return;
		}
		$lat = (float) $priv['lat'];
		$lng = (float) $priv['lng'];
		if ( 'aproximada' === $prec ) {
			$h     = hexdec( substr( hash_hmac( 'sha256', (string) $post_id, wp_salt( 'secure_auth' ) ), 0, 8 ) );
			$ang   = ( $h % 360 ) * M_PI / 180;
			$dist  = 150 + ( $h % 250 ); // metros.
			$lat  += ( $dist * cos( $ang ) ) / 111320;
			$lng  += ( $dist * sin( $ang ) ) / ( 111320 * cos( deg2rad( $lat ) ) );
			$lat   = round( $lat, 3 );
			$lng   = round( $lng, 3 );
		}
		update_post_meta( $post_id, '_inmo_lat_pub', $lat );
		update_post_meta( $post_id, '_inmo_lng_pub', $lng );
	}

	/** Título sugerido: "Departamento en arriendo en Ñuñoa". */
	public static function titulo_sugerido( $post_id ) {
		$tipo = self::termino( $post_id, self::TAX_TIPO );
		$op   = self::termino( $post_id, self::TAX_OPERACION );
		$com  = self::comuna( $post_id );
		$t    = $tipo ? Inmo_Esquema::TIPOS[ $tipo ] : 'Propiedad';
		$t   .= $op ? ' en ' . strtolower( Inmo_Esquema::OPERACIONES[ $op ] ) : '';
		$t   .= $com ? ' en ' . $com->name : '';
		return $t;
	}

	public static function al_borrar( $post_id ) {
		if ( INMO_CPT === get_post_type( $post_id ) ) {
			Inmo_Privado::borrar( $post_id );
		}
	}

	/**
	 * Crea una captación (borrador o en revisión). Usado por el formulario del propietario y datos de prueba.
	 *
	 * @return int|WP_Error
	 */
	public static function crear_captacion( array $d, $estado = 'en_revision', $autor = 0 ) {
		self::$sincronizando = true;
		$id = wp_insert_post(
			array(
				'post_type'    => INMO_CPT,
				'post_status'  => self::post_status_para( $estado, 'publica' ),
				'post_title'   => isset( $d['titulo'] ) ? sanitize_text_field( $d['titulo'] ) : 'Captación',
				'post_content' => isset( $d['descripcion'] ) ? wp_kses_post( $d['descripcion'] ) : '',
				'post_author'  => $autor ? $autor : Inmo_Ajustes::corredor_defecto(),
			),
			true
		);
		self::$sincronizando = false;
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_inmo_estado', $estado );
		update_post_meta( $id, '_inmo_visibilidad', isset( $d['visibilidad'] ) ? $d['visibilidad'] : 'publica' );
		self::asegurar_codigo( $id );
		self::guardar_publicos( $id, $d );
		if ( empty( $d['titulo'] ) ) {
			self::$sincronizando = true;
			wp_update_post( array( 'ID' => $id, 'post_title' => self::titulo_sugerido( $id ) ) );
			self::$sincronizando = false;
		}
		return $id;
	}
}
