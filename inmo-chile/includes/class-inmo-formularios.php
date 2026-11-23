<?php
/**
 * Formularios públicos y su recepción en el CRM.
 *  - Consulta por propiedad / Solicitar visita (ficha pública)
 *  - Propietarios: vender, arrendar o pedir valorización (crea captación en revisión)
 *  - Completar ficha con invitación individual (características + fotos)
 *  - Consulta general / búsqueda sin resultados
 *
 * Funcionan sin JavaScript (POST a admin-post.php + redirección con mensaje).
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Formularios {

	const LIMITE_ENVIOS  = 8;   // por IP cada 10 minutos.
	const MAX_FOTOS      = 15;

	public static function init() {
		add_action( 'admin_post_nopriv_inmo_form', array( __CLASS__, 'procesar' ) );
		add_action( 'admin_post_inmo_form', array( __CLASS__, 'procesar' ) );
		add_shortcode( 'inmo_formulario_propiedad', array( __CLASS__, 'sc_propiedad' ) );
		add_shortcode( 'inmo_formulario_propietario', array( __CLASS__, 'sc_propietario' ) );
		add_shortcode( 'inmo_completar_propiedad', array( __CLASS__, 'sc_completar' ) );
		add_shortcode( 'inmo_formulario_general', array( __CLASS__, 'sc_general' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_enqueue_style( 'inmo-public', INMO_URL . 'assets/public.css', array(), INMO_VERSION );
		wp_enqueue_script( 'inmo-public', INMO_URL . 'assets/public.js', array(), INMO_VERSION, true );
	}

	/* =====================================================================
	 * Estado del formulario (errores, valores previos, mensaje de éxito)
	 * =================================================================== */

	private static function estado( $form ) {
		static $cache = array();
		if ( isset( $cache[ $form ] ) ) {
			return $cache[ $form ];
		}
		$st  = array( 'errores' => array(), 'valores' => array(), 'ok' => null );
		$key = Inmo_Util::get( 'inmo_f' );
		if ( $key && preg_match( '/^[a-zA-Z0-9]{20}$/', $key ) ) {
			$t = get_transient( 'inmo_f_' . $key );
			if ( is_array( $t ) && isset( $t['form'] ) && $t['form'] === $form ) {
				$st = array_merge( $st, $t );
				if ( ! empty( $t['ok'] ) ) {
					delete_transient( 'inmo_f_' . $key ); // El mensaje (y un posible enlace) se muestra una sola vez.
				}
			}
		}
		$cache[ $form ] = $st;
		return $st;
	}

	private static function volver( $form, array $data, $ancla = '' ) {
		$key = wp_generate_password( 20, false, false );
		set_transient( 'inmo_f_' . $key, array_merge( array( 'form' => $form ), $data ), 15 * MINUTE_IN_SECONDS );
		$volver = isset( $_POST['_inmo_volver'] ) ? esc_url_raw( wp_unslash( $_POST['_inmo_volver'] ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$volver = wp_validate_redirect( $volver, home_url( '/' ) );
		$volver = remove_query_arg( array( 'inmo_f' ), $volver );
		wp_safe_redirect( add_query_arg( 'inmo_f', $key, $volver ) . '#' . ( $ancla ? $ancla : 'inmo-' . $form ) );
		exit;
	}

	/* =====================================================================
	 * Controles anti-spam (sin servicios externos)
	 * =================================================================== */

	private static function firma_tiempo() {
		$t = time();
		return $t . '.' . substr( hash_hmac( 'sha256', (string) $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function anti_spam() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['sitio_web'] ) ) {
			return 'spam';
		}
		$f = isset( $_POST['_inmo_t'] ) ? sanitize_text_field( wp_unslash( $_POST['_inmo_t'] ) ) : '';
		// phpcs:enable
		$p = explode( '.', $f );
		if ( 2 !== count( $p ) || ! hash_equals( substr( hash_hmac( 'sha256', $p[0], wp_salt( 'nonce' ) ), 0, 16 ), $p[1] ) ) {
			return 'El formulario expiró. Recarga la página e inténtalo de nuevo.';
		}
		$edad = time() - (int) $p[0];
		if ( $edad < 2 && ! ( defined( 'INMO_PRUEBAS' ) && INMO_PRUEBAS ) ) {
			return 'spam';
		}
		if ( $edad > DAY_IN_SECONDS ) {
			return 'El formulario expiró. Recarga la página e inténtalo de nuevo.';
		}
		$k = 'inmo_rl_' . Inmo_Util::ip_hash();
		$n = (int) get_transient( $k );
		if ( $n >= self::LIMITE_ENVIOS ) {
			return 'Recibimos varios envíos seguidos desde tu conexión. Espera unos minutos e inténtalo de nuevo.';
		}
		set_transient( $k, $n + 1, 10 * MINUTE_IN_SECONDS );
		return '';
	}

	/* =====================================================================
	 * Procesamiento
	 * =================================================================== */

	public static function procesar() {
		$form = Inmo_Util::post( '_inmo_form' );
		if ( ! in_array( $form, array( 'consulta', 'visita', 'propietario', 'completar', 'general' ), true ) ) {
			wp_die( 'Formulario no válido.', '', array( 'response' => 400 ) );
		}
		$spam = self::anti_spam();
		if ( 'spam' === $spam ) {
			// Respuesta neutra para no dar pistas a bots.
			self::volver( $form, array( 'ok' => array( 'mensaje' => 'Gracias. Recibimos tu mensaje.' ) ) );
		}
		if ( $spam ) {
			self::volver( $form, array( 'errores' => array( '_' => $spam ), 'valores' => self::valores_post() ) );
		}
		call_user_func( array( __CLASS__, 'procesar_' . $form ) );
	}

	private static function valores_post() {
		$v = array();
		foreach ( array( 'nombre', 'email', 'telefono', 'mensaje', 'fecha', 'franja', 'operacion_propietario', 'tipo', 'comuna_id', 'sector', 'dormitorios', 'banos', 'superficie', 'precio', 'moneda' ) as $k ) {
			$v[ $k ] = 'mensaje' === $k ? Inmo_Util::post_textarea( $k ) : Inmo_Util::post( $k );
		}
		$v['consentimiento'] = Inmo_Util::post( 'consentimiento' ) ? '1' : '';
		return $v;
	}

	/** Validación común de contacto. */
	private static function validar_contacto( array $v, $telefono_obligatorio ) {
		$e = array();
		if ( mb_strlen( trim( $v['nombre'] ) ) < 2 ) {
			$e['nombre'] = 'Escribe tu nombre.';
		}
		if ( ! Inmo_Util::normalizar_email( $v['email'] ) ) {
			$e['email'] = 'Escribe un correo válido, por ejemplo nombre@correo.cl.';
		}
		if ( ( $telefono_obligatorio || '' !== $v['telefono'] ) && ! Inmo_Util::normalizar_telefono( $v['telefono'] ) ) {
			$e['telefono'] = 'Escribe un teléfono chileno, por ejemplo +56 9 1234 5678.';
		}
		if ( ! $v['consentimiento'] ) {
			$e['consentimiento'] = 'Necesitamos tu autorización para contactarte.';
		}
		return $e;
	}

	private static function procesar_consulta() {
		self::procesar_por_propiedad( 'consulta' );
	}

	private static function procesar_visita() {
		self::procesar_por_propiedad( 'visita' );
	}

	private static function procesar_por_propiedad( $tipo ) {
		$v   = self::valores_post();
		$pid = (int) Inmo_Util::post( 'propiedad_id' );
		if ( ! $pid || INMO_CPT !== get_post_type( $pid ) || 'publish' !== get_post_status( $pid ) ) {
			self::volver( $tipo, array( 'errores' => array( '_' => 'La propiedad ya no está disponible.' ), 'valores' => $v ) );
		}
		$e = self::validar_contacto( $v, 'visita' === $tipo );
		if ( 'consulta' === $tipo && mb_strlen( trim( $v['mensaje'] ) ) < 5 ) {
			$e['mensaje'] = 'Escribe tu consulta.';
		}
		if ( 'visita' === $tipo ) {
			$hoy = date_create_immutable( 'today', wp_timezone() );
			$f   = date_create_immutable( $v['fecha'] . ' 00:00', wp_timezone() );
			if ( ! $v['fecha'] || ! $f || $f <= $hoy ) {
				$e['fecha'] = 'Elige una fecha a partir de mañana.';
			} elseif ( $f > $hoy->modify( '+60 days' ) ) {
				$e['fecha'] = 'Elige una fecha dentro de los próximos 60 días.';
			}
			if ( ! isset( Inmo_Consultas::FRANJAS[ $v['franja'] ] ) ) {
				$e['franja'] = 'Elige un horario preferido.';
			}
		}
		if ( $e ) {
			self::volver( $tipo, array( 'errores' => $e, 'valores' => $v ) );
		}
		$op       = Inmo_Propiedades::termino( $pid, Inmo_Propiedades::TAX_OPERACION );
		$contacto = Inmo_Contactos::upsert(
			array(
				'nombre'         => $v['nombre'],
				'email'          => $v['email'],
				'telefono'       => $v['telefono'],
				'roles'          => array( 'arriendo' === $op ? 'arrendatario' : 'comprador' ),
				'origen'         => 'visita' === $tipo ? 'web_visita' : 'web_consulta',
				'corredor_id'    => (int) get_post_field( 'post_author', $pid ),
				'consentimiento' => true,
			)
		);
		$datos = array();
		if ( 'visita' === $tipo ) {
			$datos = array( 'fecha_preferida' => $v['fecha'], 'franja' => $v['franja'] );
		}
		$qid    = Inmo_Consultas::crear(
			array(
				'tipo'           => $tipo,
				'contacto_id'    => $contacto,
				'propiedad_id'   => $pid,
				'mensaje'        => $v['mensaje'],
				'datos'          => $datos,
				'origen'         => 'web',
				'consentimiento' => true,
			)
		);
		Inmo_Correo::confirmacion_consulta( $qid );
		$codigo = get_post_meta( $pid, '_inmo_codigo', true );
		$plazo  = Inmo_Ajustes::get( 'respuesta_plazo', '1 día hábil' );
		if ( 'visita' === $tipo ) {
			$msg = sprintf(
				'Tu solicitud de visita a la propiedad %1$s quedó PENDIENTE DE CONFIRMACIÓN. Aún no es una cita: un corredor revisará la disponibilidad para el %2$s (%3$s) y te escribirá a %4$s dentro de %5$s para confirmarla o proponerte otro horario.',
				$codigo,
				Inmo_Util::fecha( $v['fecha'] ),
				strtolower( Inmo_Consultas::FRANJAS[ $v['franja'] ] ),
				Inmo_Util::normalizar_email( $v['email'] ),
				$plazo
			);
		} else {
			$msg = sprintf( 'Recibimos tu consulta por la propiedad %1$s. Un corredor te responderá a %2$s dentro de %3$s. Te enviamos una copia a tu correo.', $codigo, Inmo_Util::normalizar_email( $v['email'] ), $plazo );
		}
		self::volver( $tipo, array( 'ok' => array( 'mensaje' => $msg, 'referencia' => $codigo ) ), 'inmo-contacto-propiedad' );
	}

	private static function procesar_propietario() {
		$v      = self::valores_post();
		$accion = $v['operacion_propietario'];
		$e      = self::validar_contacto( $v, true );
		if ( ! in_array( $accion, array( 'vender', 'arrendar', 'valorizar' ), true ) ) {
			$e['operacion_propietario'] = 'Elige qué quieres hacer con tu propiedad.';
		}
		if ( ! isset( Inmo_Esquema::TIPOS[ $v['tipo'] ] ) ) {
			$e['tipo'] = 'Elige el tipo de propiedad.';
		}
		$comuna = get_term( (int) $v['comuna_id'], Inmo_Propiedades::TAX_UBICACION );
		if ( ! $comuna || is_wp_error( $comuna ) || ! $comuna->parent ) {
			$e['comuna_id'] = 'Elige la comuna.';
		}
		foreach ( array( 'dormitorios', 'banos', 'superficie', 'precio' ) as $k ) {
			if ( '' !== $v[ $k ] && ( '' === Inmo_Util::parse_numero( $v[ $k ] ) || Inmo_Util::parse_numero( $v[ $k ] ) < 0 ) ) {
				$e[ $k ] = 'Escribe solo números.';
			}
		}
		if ( $e ) {
			self::volver( 'propietario', array( 'errores' => $e, 'valores' => $v ) );
		}

		$contacto = Inmo_Contactos::upsert(
			array(
				'nombre'         => $v['nombre'],
				'email'          => $v['email'],
				'telefono'       => $v['telefono'],
				'roles'          => array( 'propietario' ),
				'origen'         => 'web_propietario',
				'consentimiento' => true,
			)
		);
		$resumen = array(
			'accion'      => $accion,
			'tipo'        => $v['tipo'],
			'comuna'      => $comuna->name,
			'sector'      => $v['sector'],
			'dormitorios' => $v['dormitorios'],
			'banos'       => $v['banos'],
			'superficie'  => $v['superficie'],
			'precio'      => $v['precio'],
			'moneda'      => $v['moneda'],
		);

		if ( 'valorizar' === $accion ) {
			$qid   = Inmo_Consultas::crear( array( 'tipo' => 'valorizacion', 'contacto_id' => $contacto, 'mensaje' => $v['mensaje'], 'datos' => $resumen, 'origen' => 'web', 'consentimiento' => true ) );
			$est   = array();
			$entr  = array( 'tipo' => $v['tipo'], 'comuna_id' => $comuna->term_id, 'superficie' => Inmo_Util::parse_numero( $v['superficie'] ), 'dormitorios' => $v['dormitorios'] );
			if ( '' !== $entr['superficie'] && $entr['superficie'] > 0 ) {
				foreach ( array( 'venta', 'arriendo' ) as $op_v ) {
					$r = Inmo_Valorizacion::estimar( array_merge( $entr, array( 'operacion' => $op_v ) ) );
					Inmo_Valorizacion::registrar( $r, 0, $qid, $entr );
					if ( $r['suficiente'] && 'baja' !== $r['confianza'] ) {
						$est[ $op_v ] = $r;
					}
				}
			}
			Inmo_Correo::confirmacion_consulta( $qid );
			$plazo = Inmo_Ajustes::get( 'respuesta_plazo' );
			$ok    = array( 'mensaje' => 'Recibimos tu solicitud de valorización. Un corredor te contactará dentro de ' . $plazo . ' para coordinar una visita y entregarte una valorización profesional.' );
			if ( $est && (int) Inmo_Ajustes::get( 'valorizacion_publica', 1 ) ) {
				$ok['estimacion'] = array();
				foreach ( $est as $op_v => $r ) {
					$ok['estimacion'][] = array( 'etiqueta' => 'venta' === $op_v ? 'Valor de venta estimado' : 'Arriendo mensual estimado', 'rango' => Inmo_Valorizacion::texto_rango( $r ), 'n' => $r['n'], 'ambito' => $r['ambito'], 'confianza' => Inmo_Valorizacion::CONFIANZA[ $r['confianza'] ] );
				}
			} elseif ( '' === $entr['superficie'] ) {
				$ok['mensaje'] .= ' Si nos indicas la superficie aproximada, podremos darte de inmediato un rango referencial.';
			}
			self::volver( 'propietario', array( 'ok' => $ok ) );
		}

		$op  = 'arrendar' === $accion ? 'arriendo' : 'venta';
		$sup = 'terreno' === $v['tipo'] ? 'sup_terreno' : 'sup_util';
		$pid = Inmo_Propiedades::crear_captacion(
			array(
				'tipo'        => $v['tipo'],
				'operacion'   => $op,
				'comuna_id'   => $comuna->term_id,
				'sector'      => $v['sector'],
				'dormitorios' => $v['dormitorios'],
				'banos'       => $v['banos'],
				$sup          => $v['superficie'],
				'precio'      => $v['precio'],
				'moneda'      => isset( Inmo_Esquema::MONEDAS[ $v['moneda'] ] ) ? $v['moneda'] : Inmo_Esquema::moneda_defecto( $op ),
				'descripcion' => '',
			),
			'en_revision',
			Inmo_Ajustes::corredor_defecto()
		);
		if ( is_wp_error( $pid ) ) {
			self::volver( 'propietario', array( 'errores' => array( '_' => 'No pudimos registrar la propiedad. Inténtalo nuevamente o llámanos.' ), 'valores' => $v ) );
		}
		Inmo_Contactos::vincular_titular( $pid, $contacto );
		if ( $v['mensaje'] ) {
			Inmo_Privado::guardar( $pid, array( 'notas' => 'Mensaje del propietario: ' . $v['mensaje'] ) );
		}
		$inv = Inmo_Invitaciones::crear( $pid, $contacto );
		$qid = Inmo_Consultas::crear( array( 'tipo' => 'captacion', 'contacto_id' => $contacto, 'propiedad_id' => $pid, 'mensaje' => $v['mensaje'], 'datos' => $resumen, 'origen' => 'web', 'consentimiento' => true ) );
		Inmo_Correo::confirmacion_consulta( $qid, array( 'invitacion_url' => $inv['url'], 'invitacion_vence' => $inv['vence'] ) );
		$codigo = get_post_meta( $pid, '_inmo_codigo', true );
		self::volver(
			'propietario',
			array(
				'ok' => array(
					'mensaje'          => sprintf( 'Recibimos tu propiedad con el código %1$s. Quedó en revisión: un corredor la revisará dentro de %2$s y te contactará antes de publicarla.', $codigo, Inmo_Ajustes::get( 'respuesta_plazo' ) ),
					'referencia'       => $codigo,
					'invitacion_url'   => $inv['url'],
					'invitacion_vence' => $inv['vence'],
				),
			)
		);
	}

	private static function procesar_general() {
		$v = self::valores_post();
		$e = self::validar_contacto( $v, false );
		if ( $e ) {
			self::volver( 'general', array( 'errores' => $e, 'valores' => $v ) );
		}
		$busqueda = array();
		$raw      = Inmo_Util::post( 'busqueda' );
		if ( $raw ) {
			parse_str( $raw, $bq );
			foreach ( array( 'operacion', 'tipo', 'comuna', 'precio_min', 'precio_max', 'moneda', 'dormitorios' ) as $k ) {
				if ( ! empty( $bq[ $k ] ) ) {
					$busqueda[ $k ] = sanitize_text_field( $bq[ $k ] );
				}
			}
		}
		$pref = array();
		if ( $busqueda ) {
			$pref = array_filter(
				array(
					'operacion'      => isset( $busqueda['operacion'] ) ? $busqueda['operacion'] : '',
					'tipos'          => isset( $busqueda['tipo'] ) ? array( $busqueda['tipo'] ) : array(),
					'comunas'        => isset( $busqueda['comuna'] ) ? array( $busqueda['comuna'] ) : array(),
					'presupuesto_max' => isset( $busqueda['precio_max'] ) ? $busqueda['precio_max'] : '',
					'moneda'         => isset( $busqueda['moneda'] ) ? $busqueda['moneda'] : '',
					'dormitorios'    => isset( $busqueda['dormitorios'] ) ? $busqueda['dormitorios'] : '',
				)
			);
		}
		$roles = array();
		if ( isset( $busqueda['operacion'] ) ) {
			$roles[] = 'arriendo' === $busqueda['operacion'] ? 'arrendatario' : 'comprador';
		}
		$contacto = Inmo_Contactos::upsert(
			array(
				'nombre'         => $v['nombre'],
				'email'          => $v['email'],
				'telefono'       => $v['telefono'],
				'roles'          => $roles,
				'preferencias'   => $pref,
				'origen'         => $busqueda ? 'web_busqueda' : 'web_consulta',
				'consentimiento' => true,
			)
		);
		$qid = Inmo_Consultas::crear( array( 'tipo' => 'general', 'contacto_id' => $contacto, 'mensaje' => $v['mensaje'], 'datos' => $busqueda ? array( 'busqueda' => $busqueda ) : array(), 'origen' => 'web', 'consentimiento' => true ) );
		Inmo_Correo::confirmacion_consulta( $qid );
		self::volver( 'general', array( 'ok' => array( 'mensaje' => 'Recibimos tu mensaje. Un corredor te contactará dentro de ' . Inmo_Ajustes::get( 'respuesta_plazo' ) . ( $busqueda ? ' con propiedades que calcen con tu búsqueda.' : '.' ) ) ) );
	}

	private static function procesar_completar() {
		$token = Inmo_Util::post( 't' );
		$inv   = Inmo_Invitaciones::validar( $token );
		if ( is_wp_error( $inv ) ) {
			self::volver( 'completar', array( 'errores' => array( '_' => $inv->get_error_message() ) ) );
		}
		$pid  = (int) $inv->propiedad_id;
		$tipo = Inmo_Propiedades::termino( $pid, Inmo_Propiedades::TAX_TIPO );
		$op   = Inmo_Propiedades::termino( $pid, Inmo_Propiedades::TAX_OPERACION );

		$datos = array();
		foreach ( Inmo_Esquema::campos_para( $tipo, $op ) as $k => $c ) {
			$val = Inmo_Util::post( 'c_' . $k );
			if ( '' !== $val ) {
				$datos[ $k ] = $val;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$equip = isset( $_POST['c_equipamiento'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['c_equipamiento'] ) ) : array();
		if ( $equip ) {
			$datos['equipamiento'] = $equip;
		}

		// Fotos → almacenamiento privado hasta que la corredora las autorice.
		$errores_fotos = array();
		$recibidas     = 0;
		if ( ! empty( $_FILES['fotos'] ) && is_array( $_FILES['fotos']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$files = $_FILES['fotos']; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
			$n     = count( $files['name'] );
			for ( $i = 0; $i < $n && $i < self::MAX_FOTOS; $i++ ) {
				if ( UPLOAD_ERR_NO_FILE === (int) $files['error'][ $i ] ) {
					continue;
				}
				$r = Inmo_Documentos::guardar_subida(
					array(
						'name'     => $files['name'][ $i ],
						'type'     => $files['type'][ $i ],
						'tmp_name' => $files['tmp_name'][ $i ],
						'error'    => $files['error'][ $i ],
						'size'     => $files['size'][ $i ],
					),
					$pid,
					'foto_propietario',
					(int) $inv->contacto_id,
					true
				);
				if ( is_wp_error( $r ) ) {
					$errores_fotos[] = sanitize_file_name( $files['name'][ $i ] ) . ': ' . $r->get_error_message();
				} else {
					$recibidas++;
				}
			}
		}

		Inmo_Propiedades::guardar_publicos( $pid, $datos );
		$desc = Inmo_Util::post_textarea( 'c_descripcion' );
		if ( $desc ) {
			$post = get_post( $pid );
			if ( '' === trim( $post->post_content ) ) {
				wp_update_post( array( 'ID' => $pid, 'post_content' => $desc ) );
			} else {
				Inmo_Historial::registrar( 'nota', 'Descripción propuesta por el propietario: ' . $desc, array( 'propiedad_id' => $pid, 'contacto_id' => $inv->contacto_id ) );
			}
		}
		Inmo_Invitaciones::marcar_usada( $inv->id );
		Inmo_Historial::registrar( 'propietario_completo', sprintf( 'Completó %d campos y envió %d foto(s)', count( $datos ), $recibidas ), array( 'propiedad_id' => $pid, 'contacto_id' => $inv->contacto_id ) );

		$msg = sprintf( '¡Gracias! Recibimos la información de tu propiedad %1$s%2$s. Un corredor revisará los datos y las fotos antes de publicarla.', get_post_meta( $pid, '_inmo_codigo', true ), $recibidas ? ' y ' . $recibidas . ' foto(s)' : '' );
		if ( $errores_fotos ) {
			$msg .= ' Algunas fotos no se pudieron recibir: ' . implode( '; ', $errores_fotos ) . '. Puedes enviárselas a tu corredor.';
		}
		self::volver( 'completar', array( 'ok' => array( 'mensaje' => $msg ) ) );
	}

	/* =====================================================================
	 * Render
	 * =================================================================== */

	private static function abrir_form( $form, array $extra = array(), $multipart = false ) {
		global $wp;
		$volver = home_url( add_query_arg( array(), isset( $wp->request ) ? $wp->request : '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET ) ) {
			$volver = add_query_arg( array_map( 'rawurlencode', wp_unslash( $_GET ) ), trailingslashit( $volver ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
		}
		$h  = '<form class="inmo-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ' novalidate data-inmo-form>';
		$h .= '<input type="hidden" name="action" value="inmo_form">';
		$h .= '<input type="hidden" name="_inmo_form" value="' . esc_attr( $form ) . '">';
		$h .= '<input type="hidden" name="_inmo_t" value="' . esc_attr( self::firma_tiempo() ) . '">';
		$h .= '<input type="hidden" name="_inmo_volver" value="' . esc_url( remove_query_arg( 'inmo_f', $volver ) ) . '">';
		foreach ( $extra as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		// Trampa para bots (oculta para personas y lectores de pantalla).
		$h .= '<div class="inmo-hp" aria-hidden="true"><label>Sitio web <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>';
		return $h;
	}

	private static function resumen_errores( $form, array $errores ) {
		if ( ! $errores ) {
			return '';
		}
		$h = '<div class="inmo-alerta inmo-alerta--error" role="alert" tabindex="-1" data-inmo-foco><p><strong>Revisa el formulario:</strong></p><ul>';
		foreach ( $errores as $campo => $msg ) {
			$h .= '_' === $campo ? '<li>' . esc_html( $msg ) . '</li>' : '<li><a href="#' . esc_attr( $form . '-' . $campo ) . '">' . esc_html( $msg ) . '</a></li>';
		}
		return $h . '</ul></div>';
	}

	private static function exito( array $ok ) {
		$h = '<div class="inmo-alerta inmo-alerta--ok" role="status" tabindex="-1" data-inmo-foco><p>' . esc_html( $ok['mensaje'] ) . '</p>';
		if ( ! empty( $ok['estimacion'] ) ) {
			$h .= '<div class="inmo-estimacion" id="inmo-estimacion"><p><strong>Estimación referencial automática</strong></p><dl>';
			foreach ( $ok['estimacion'] as $e ) {
				$h .= '<div><dt>' . esc_html( $e['etiqueta'] ) . '</dt><dd>' . esc_html( $e['rango'] ) . '</dd><dd class="inmo-ayuda">' . esc_html( 'Basada en ' . $e['n'] . ' propiedades comparables en ' . $e['ambito'] . ' · confianza ' . strtolower( $e['confianza'] ) ) . '</dd></div>';
			}
			$h .= '</dl><p class="inmo-ayuda">Es un cálculo estadístico con propiedades similares, sin visitar tu propiedad: no considera su estado, terminaciones, vista ni orientación, y no constituye una tasación. Tu corredor la ajustará en la visita.</p></div>';
		}
		if ( ! empty( $ok['invitacion_url'] ) ) {
			$h .= '<p>¿Tienes las fotos a mano? Puedes agregar características y fotos ahora mismo con tu enlace personal (válido hasta el ' . esc_html( Inmo_Util::fecha( $ok['invitacion_vence'] ) ) . '). También te lo enviamos por correo.</p>';
			$h .= '<p><a class="inmo-boton" href="' . esc_url( $ok['invitacion_url'] ) . '">Completar características y fotos</a></p>';
		}
		return $h . '</div>';
	}

	/**
	 * Campo accesible.
	 *
	 * @param array $o name,label,type,required,opciones,attrs,ayuda,valor
	 */
	public static function campo( $form, array $o, array $valores, array $errores ) {
		$o    = wp_parse_args( $o, array( 'type' => 'text', 'required' => false, 'opciones' => array(), 'attrs' => array(), 'ayuda' => '', 'valor' => null, 'grupos' => false ) );
		$name = $o['name'];
		$id   = $form . '-' . $name;
		$val  = null !== $o['valor'] ? $o['valor'] : ( isset( $valores[ $name ] ) ? $valores[ $name ] : '' );
		$err  = isset( $errores[ $name ] ) ? $errores[ $name ] : '';
		$desc = array();
		if ( $o['ayuda'] ) {
			$desc[] = $id . '-ayuda';
		}
		if ( $err ) {
			$desc[] = $id . '-error';
		}
		$aria = ( $desc ? ' aria-describedby="' . esc_attr( implode( ' ', $desc ) ) . '"' : '' ) . ( $err ? ' aria-invalid="true"' : '' ) . ( $o['required'] ? ' required aria-required="true"' : '' );
		foreach ( $o['attrs'] as $k => $v ) {
			$aria .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		$req = $o['required'] ? ' <span class="inmo-req" aria-hidden="true">*</span>' : ' <span class="inmo-opc">(opcional)</span>';

		$h = '<div class="inmo-campo inmo-campo--' . esc_attr( $o['type'] ) . ( $err ? ' inmo-campo--error' : '' ) . '">';
		if ( 'checkbox' === $o['type'] ) {
			$h .= '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $val, true, false ) . $aria . '>';
			$h .= ' <label for="' . esc_attr( $id ) . '">' . wp_kses( $o['label'], array( 'a' => array( 'href' => array(), 'target' => array() ) ) ) . ( $o['required'] ? ' <span class="inmo-req" aria-hidden="true">*</span>' : '' ) . '</label>';
		} elseif ( 'radios' === $o['type'] ) {
			$h  = '<fieldset class="inmo-campo inmo-campo--radios' . ( $err ? ' inmo-campo--error' : '' ) . '" id="' . esc_attr( $id ) . '"' . ( $err ? ' aria-describedby="' . esc_attr( $id ) . '-error"' : '' ) . '><legend>' . esc_html( $o['label'] ) . $req . '</legend><div class="inmo-radios">';
			foreach ( $o['opciones'] as $k => $lbl ) {
				$rid = $id . '-' . $k;
				$h  .= '<label class="inmo-radio" for="' . esc_attr( $rid ) . '"><input type="radio" id="' . esc_attr( $rid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $k ) . '"' . checked( (string) $val, (string) $k, false ) . ( $o['required'] ? ' required' : '' ) . '> <span>' . esc_html( $lbl ) . '</span></label>';
			}
			$h .= '</div>';
			if ( $err ) {
				$h .= '<p class="inmo-error" id="' . esc_attr( $id ) . '-error">' . esc_html( $err ) . '</p>';
			}
			return $h . '</fieldset>';
		} else {
			$h .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $o['label'] ) . $req . '</label>';
			if ( $o['ayuda'] ) {
				$h .= '<p class="inmo-ayuda" id="' . esc_attr( $id ) . '-ayuda">' . esc_html( $o['ayuda'] ) . '</p>';
			}
			if ( 'textarea' === $o['type'] ) {
				$h .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4"' . $aria . '>' . esc_textarea( $val ) . '</textarea>';
			} elseif ( 'select' === $o['type'] ) {
				$h .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . '><option value="">Selecciona…</option>';
				if ( $o['grupos'] ) {
					foreach ( $o['opciones'] as $grupo => $ops ) {
						$h .= '<optgroup label="' . esc_attr( $grupo ) . '">';
						foreach ( $ops as $k => $lbl ) {
							$h .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $val, (string) $k, false ) . '>' . esc_html( $lbl ) . '</option>';
						}
						$h .= '</optgroup>';
					}
				} else {
					foreach ( $o['opciones'] as $k => $lbl ) {
						$h .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $val, (string) $k, false ) . '>' . esc_html( $lbl ) . '</option>';
					}
				}
				$h .= '</select>';
			} else {
				$h .= '<input type="' . esc_attr( $o['type'] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '"' . $aria . '>';
			}
		}
		if ( $err ) {
			$h .= '<p class="inmo-error" id="' . esc_attr( $id ) . '-error">' . esc_html( $err ) . '</p>';
		}
		return $h . '</div>';
	}

	private static function campos_contacto( $form, array $val, array $err, $tel_obligatorio ) {
		$h  = self::campo( $form, array( 'name' => 'nombre', 'label' => 'Nombre y apellido', 'required' => true, 'attrs' => array( 'autocomplete' => 'name' ) ), $val, $err );
		$h .= '<div class="inmo-fila">';
		$h .= self::campo( $form, array( 'name' => 'email', 'label' => 'Correo', 'type' => 'email', 'required' => true, 'attrs' => array( 'autocomplete' => 'email', 'inputmode' => 'email' ) ), $val, $err );
		$h .= self::campo( $form, array( 'name' => 'telefono', 'label' => 'Teléfono', 'type' => 'tel', 'required' => $tel_obligatorio, 'attrs' => array( 'autocomplete' => 'tel', 'placeholder' => '+56 9 1234 5678' ) ), $val, $err );
		$h .= '</div>';
		return $h;
	}

	private static function consentimiento( $form, array $val, array $err ) {
		$priv = get_privacy_policy_url();
		$lbl  = 'Autorizo a ' . esc_html( Inmo_Ajustes::get( 'marca_nombre' ) ) . ' a contactarme y a tratar mis datos para responder esta solicitud' . ( $priv ? ', según la <a href="' . esc_url( $priv ) . '" target="_blank">política de privacidad</a>' : '' ) . '.';
		return self::campo( $form, array( 'name' => 'consentimiento', 'label' => $lbl, 'type' => 'checkbox', 'required' => true ), $val, $err );
	}

	/** Bloque "Consultar por esta propiedad" + "Solicitar visita" (pestañas accesibles). */
	public static function sc_propiedad( $atts = array() ) {
		$atts = shortcode_atts( array( 'id' => get_the_ID() ), $atts );
		$pid  = (int) $atts['id'];
		if ( ! $pid || 'publish' !== get_post_status( $pid ) ) {
			return '';
		}
		$codigo = get_post_meta( $pid, '_inmo_codigo', true );
		$c      = self::estado( 'consulta' );
		$v      = self::estado( 'visita' );
		$activa = ( $v['errores'] || $v['ok'] ) ? 'visita' : 'consulta';
		$ok     = $c['ok'] ? $c['ok'] : $v['ok'];

		$h = '<div class="inmo-contacto-propiedad" id="inmo-contacto-propiedad">';
		if ( $ok ) {
			$h .= self::exito( $ok );
		}
		$h .= '<div class="inmo-tabs" role="tablist" aria-label="Contactar por la propiedad ' . esc_attr( $codigo ) . '">';
		$h .= '<button type="button" role="tab" id="inmo-tab-consulta" aria-controls="inmo-consulta" aria-selected="' . ( 'consulta' === $activa ? 'true' : 'false' ) . '"' . ( 'consulta' === $activa ? '' : ' tabindex="-1"' ) . '>Consultar por esta propiedad</button>';
		$h .= '<button type="button" role="tab" id="inmo-tab-visita" aria-controls="inmo-visita" aria-selected="' . ( 'visita' === $activa ? 'true' : 'false' ) . '"' . ( 'visita' === $activa ? '' : ' tabindex="-1"' ) . '>Solicitar visita</button>';
		$h .= '</div>';

		// Consulta.
		$val = $c['valores'];
		if ( empty( $val['mensaje'] ) ) {
			$val['mensaje'] = sprintf( 'Hola, me interesa la propiedad %s. ¿Me pueden dar más información?', $codigo );
		}
		$h .= '<div class="inmo-tabpanel" role="tabpanel" id="inmo-consulta" aria-labelledby="inmo-tab-consulta"' . ( 'consulta' === $activa ? '' : ' hidden' ) . '>';
		$h .= '<h3 class="inmo-panel-titulo">Consultar por ' . esc_html( $codigo ) . '</h3>';
		$h .= self::resumen_errores( 'consulta', $c['errores'] );
		$h .= self::abrir_form( 'consulta', array( 'propiedad_id' => $pid ) );
		$h .= self::campos_contacto( 'consulta', $val, $c['errores'], false );
		$h .= self::campo( 'consulta', array( 'name' => 'mensaje', 'label' => 'Tu consulta', 'type' => 'textarea', 'required' => true ), $val, $c['errores'] );
		$h .= self::consentimiento( 'consulta', $val, $c['errores'] );
		$h .= '<p class="inmo-nota">Responderemos dentro de ' . esc_html( Inmo_Ajustes::get( 'respuesta_plazo' ) ) . '. Tu mensaje incluirá el código ' . esc_html( $codigo ) . '.</p>';
		$h .= '<button type="submit" class="inmo-boton">Enviar consulta</button></form></div>';

		// Visita.
		$val    = $v['valores'];
		$manana = date_create_immutable( 'tomorrow', wp_timezone() );
		$h     .= '<div class="inmo-tabpanel" role="tabpanel" id="inmo-visita" aria-labelledby="inmo-tab-visita"' . ( 'visita' === $activa ? '' : ' hidden' ) . '>';
		$h     .= '<h3 class="inmo-panel-titulo">Solicitar visita a ' . esc_html( $codigo ) . '</h3>';
		$h     .= self::resumen_errores( 'visita', $v['errores'] );
		$h     .= self::abrir_form( 'visita', array( 'propiedad_id' => $pid ) );
		$h     .= self::campos_contacto( 'visita', $val, $v['errores'], true );
		$h     .= '<div class="inmo-fila">';
		$h     .= self::campo( 'visita', array( 'name' => 'fecha', 'label' => 'Fecha preferida', 'type' => 'date', 'required' => true, 'attrs' => array( 'min' => $manana->format( 'Y-m-d' ), 'max' => $manana->modify( '+59 days' )->format( 'Y-m-d' ) ) ), $val, $v['errores'] );
		$h     .= self::campo( 'visita', array( 'name' => 'franja', 'label' => 'Horario preferido', 'type' => 'select', 'required' => true, 'opciones' => Inmo_Consultas::FRANJAS ), $val, $v['errores'] );
		$h     .= '</div>';
		$h     .= self::campo( 'visita', array( 'name' => 'mensaje', 'label' => 'Comentario', 'type' => 'textarea' ), $val, $v['errores'] );
		$h     .= self::consentimiento( 'visita', $val, $v['errores'] );
		$h     .= '<p class="inmo-nota">La visita quedará <strong>pendiente</strong> hasta que un corredor la confirme contigo.</p>';
		$h     .= '<button type="submit" class="inmo-boton">Solicitar visita</button></form></div>';
		return $h . '</div>';
	}

	/** Formulario corto para propietarios. */
	public static function sc_propietario() {
		$st  = self::estado( 'propietario' );
		$val = $st['valores'];
		$err = $st['errores'];
		$h   = '<div class="inmo-propietario" id="inmo-propietario">';
		if ( $st['ok'] ) {
			return $h . self::exito( $st['ok'] ) . '</div>';
		}
		if ( empty( $val['operacion_propietario'] ) ) {
			$pre = Inmo_Util::get( 'quiero' );
			$val['operacion_propietario'] = in_array( $pre, array( 'vender', 'arrendar', 'valorizar' ), true ) ? $pre : '';
		}
		$h .= self::resumen_errores( 'propietario', $err );
		$h .= self::abrir_form( 'propietario' );
		$h .= '<fieldset class="inmo-paso"><legend>1. ¿Qué quieres hacer?</legend>';
		$h .= self::campo( 'propietario', array( 'name' => 'operacion_propietario', 'label' => 'Quiero', 'type' => 'radios', 'required' => true, 'opciones' => array( 'vender' => 'Vender', 'arrendar' => 'Dar en arriendo', 'valorizar' => 'Solo saber cuánto vale' ) ), $val, $err );
		$h .= '</fieldset>';
		$h .= '<fieldset class="inmo-paso"><legend>2. Tu propiedad</legend><div class="inmo-fila">';
		$h .= self::campo( 'propietario', array( 'name' => 'tipo', 'label' => 'Tipo de propiedad', 'type' => 'select', 'required' => true, 'opciones' => Inmo_Esquema::TIPOS ), $val, $err );
		$h .= self::campo( 'propietario', array( 'name' => 'comuna_id', 'label' => 'Comuna', 'type' => 'select', 'required' => true, 'opciones' => Inmo_Busqueda::comunas( false, true ), 'grupos' => true ), $val, $err );
		$h .= '</div>';
		$h .= self::campo( 'propietario', array( 'name' => 'sector', 'label' => 'Sector o barrio', 'ayuda' => 'No escribas la dirección exacta; te la pediremos por teléfono.' ), $val, $err );
		$h .= '<div class="inmo-fila inmo-fila--3">';
		$h .= self::campo( 'propietario', array( 'name' => 'dormitorios', 'label' => 'Dormitorios', 'type' => 'number', 'attrs' => array( 'min' => 0, 'max' => 20, 'inputmode' => 'numeric' ) ), $val, $err );
		$h .= self::campo( 'propietario', array( 'name' => 'banos', 'label' => 'Baños', 'type' => 'number', 'attrs' => array( 'min' => 0, 'max' => 20, 'inputmode' => 'numeric' ) ), $val, $err );
		$h .= self::campo( 'propietario', array( 'name' => 'superficie', 'label' => 'Superficie aprox. (m²)', 'type' => 'number', 'attrs' => array( 'min' => 0, 'inputmode' => 'decimal' ) ), $val, $err );
		$h .= '</div><div class="inmo-fila">';
		$h .= self::campo( 'propietario', array( 'name' => 'precio', 'label' => 'Precio o renta esperada', 'attrs' => array( 'inputmode' => 'decimal' ) ), $val, $err );
		$h .= self::campo( 'propietario', array( 'name' => 'moneda', 'label' => 'Moneda', 'type' => 'select', 'opciones' => Inmo_Esquema::MONEDAS ), $val, $err );
		$h .= '</div></fieldset>';
		$h .= '<fieldset class="inmo-paso"><legend>3. Tus datos de contacto</legend>';
		$h .= self::campos_contacto( 'propietario', $val, $err, true );
		$h .= self::campo( 'propietario', array( 'name' => 'mensaje', 'label' => 'Algo más que debamos saber', 'type' => 'textarea' ), $val, $err );
		$h .= self::consentimiento( 'propietario', $val, $err );
		$h .= '</fieldset>';
		$h .= '<p class="inmo-nota">Después de enviar recibirás un enlace personal para agregar características y fotos cuando quieras (vence en ' . (int) Inmo_Ajustes::get( 'invitacion_dias', 7 ) . ' días).</p>';
		$h .= '<button type="submit" class="inmo-boton">Enviar mi propiedad</button></form>';
		return $h . '</div>';
	}

	/** Página de la invitación individual. */
	public static function sc_completar() {
		$st = self::estado( 'completar' );
		$h  = '<div class="inmo-completar" id="inmo-completar">';
		if ( $st['ok'] ) {
			return $h . self::exito( $st['ok'] ) . '</div>';
		}
		if ( ! empty( $st['errores']['_'] ) ) {
			return $h . self::resumen_errores( 'completar', $st['errores'] ) . '</div>';
		}
		$token = Inmo_Util::get( 't' );
		$inv   = Inmo_Invitaciones::validar( $token );
		if ( is_wp_error( $inv ) ) {
			return $h . '<div class="inmo-alerta inmo-alerta--error" role="alert"><p>' . esc_html( $inv->get_error_message() ) . '</p></div></div>';
		}
		$pid  = (int) $inv->propiedad_id;
		$tipo = Inmo_Propiedades::termino( $pid, Inmo_Propiedades::TAX_TIPO );
		$op   = Inmo_Propiedades::termino( $pid, Inmo_Propiedades::TAX_OPERACION );
		$com  = Inmo_Propiedades::comuna( $pid );

		$h .= '<p class="inmo-resumen-prop"><strong>' . esc_html( get_post_meta( $pid, '_inmo_codigo', true ) ) . '</strong> · ' . esc_html( Inmo_Esquema::TIPOS[ $tipo ] ?? '' ) . ' para ' . esc_html( strtolower( Inmo_Esquema::OPERACIONES[ $op ] ?? '' ) ) . ( $com ? ' en ' . esc_html( $com->name ) : '' ) . '</p>';
		$h .= '<p class="inmo-nota">Este enlace es personal y vence el ' . esc_html( Inmo_Util::fecha( $inv->vence, true ) ) . '. Completa lo que sepas; tu corredor revisará todo antes de publicar.</p>';
		$h .= self::abrir_form( 'completar', array( 't' => $token ), true );

		$grupos = array( 'caracteristicas' => 'Características', 'precio' => 'Gastos', 'arriendo' => 'Condiciones de arriendo' );
		foreach ( $grupos as $g => $titulo ) {
			$campos = Inmo_Esquema::campos_para( $tipo, $op, $g );
			if ( ! $campos ) {
				continue;
			}
			$h .= '<fieldset class="inmo-paso"><legend>' . esc_html( $titulo ) . '</legend><div class="inmo-fila inmo-fila--3">';
			foreach ( $campos as $k => $c ) {
				$actual = get_post_meta( $pid, '_inmo_' . $k, true );
				$o      = array( 'name' => 'c_' . $k, 'label' => $c['label'] . ( isset( $c['unidad'] ) ? ' (' . $c['unidad'] . ')' : '' ), 'valor' => $actual );
				if ( 'numero' === $c['tipo'] ) {
					$o['type']  = 'number';
					$o['attrs'] = array( 'min' => 0, 'step' => isset( $c['step'] ) ? $c['step'] : '1', 'inputmode' => 'decimal' );
				} elseif ( 'select' === $c['tipo'] ) {
					$o['type']     = 'select';
					$o['opciones'] = $c['opciones'];
				} elseif ( 'fecha' === $c['tipo'] ) {
					$o['type'] = 'date';
				}
				$h .= self::campo( 'completar', $o, array(), array() );
			}
			$h .= '</div></fieldset>';
		}
		$equip = Inmo_Esquema::equipamiento( $tipo );
		if ( $equip ) {
			$sel = (array) get_post_meta( $pid, '_inmo_equipamiento', true );
			$h  .= '<fieldset class="inmo-paso"><legend>Equipamiento</legend><div class="inmo-checks">';
			foreach ( $equip as $k => $lbl ) {
				$h .= '<label class="inmo-check"><input type="checkbox" name="c_equipamiento[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $sel, true ), true, false ) . '> ' . esc_html( $lbl ) . '</label>';
			}
			$h .= '</div></fieldset>';
		}
		$h .= '<fieldset class="inmo-paso"><legend>Descripción y fotos</legend>';
		$h .= self::campo( 'completar', array( 'name' => 'c_descripcion', 'label' => 'Cuéntanos de tu propiedad', 'type' => 'textarea', 'ayuda' => 'Lo que más te gusta, remodelaciones, entorno. No incluyas la dirección exacta.' ), array(), array() );
		$h .= '<div class="inmo-campo"><label for="completar-fotos">Fotos <span class="inmo-opc">(hasta ' . self::MAX_FOTOS . ', JPG, PNG o WebP de máx. 10 MB)</span></label>';
		$h .= '<p class="inmo-ayuda" id="completar-fotos-ayuda">Las fotos no se publican hasta que tu corredor las autorice.</p>';
		$h .= '<input type="file" id="completar-fotos" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="completar-fotos-ayuda" data-inmo-max="' . self::MAX_FOTOS . '"></div>';
		$h .= '</fieldset>';
		$h .= '<button type="submit" class="inmo-boton">Enviar información</button></form>';
		return $h . '</div>';
	}

	/** Consulta general (p. ej. búsqueda sin resultados o contacto). */
	public static function sc_general( $atts = array() ) {
		$atts = shortcode_atts( array( 'busqueda' => '', 'titulo' => '' ), $atts );
		$st   = self::estado( 'general' );
		$h    = '<div class="inmo-general" id="inmo-general">';
		if ( $st['ok'] ) {
			return $h . self::exito( $st['ok'] ) . '</div>';
		}
		$val = $st['valores'];
		if ( $atts['titulo'] ) {
			$h .= '<h2 class="inmo-panel-titulo">' . esc_html( $atts['titulo'] ) . '</h2>';
		}
		$h .= self::resumen_errores( 'general', $st['errores'] );
		$h .= self::abrir_form( 'general', $atts['busqueda'] ? array( 'busqueda' => $atts['busqueda'] ) : array() );
		$h .= self::campos_contacto( 'general', $val, $st['errores'], false );
		$h .= self::campo( 'general', array( 'name' => 'mensaje', 'label' => $atts['busqueda'] ? '¿Qué estás buscando?' : 'Mensaje', 'type' => 'textarea' ), $val, $st['errores'] );
		$h .= self::consentimiento( 'general', $val, $st['errores'] );
		$h .= '<button type="submit" class="inmo-boton">Enviar</button></form>';
		return $h . '</div>';
	}
}
