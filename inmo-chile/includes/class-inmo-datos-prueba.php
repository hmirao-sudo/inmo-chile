<?php
/**
 * Datos de prueba FICTICIOS: 2 corredores, 1 propietaria, 2 inmuebles con imágenes ilustrativas generadas
 * localmente (sin derechos de terceros) y un mandato de muestra en el almacenamiento privado.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Datos_Prueba {

	const OPCION = 'inmo_datos_prueba';

	public static function cargados() {
		$d = get_option( self::OPCION );
		return is_array( $d ) && ! empty( $d['propiedades'] );
	}

	/**
	 * @return array Resumen de lo creado.
	 */
	public static function cargar() {
		if ( self::cargados() ) {
			return get_option( self::OPCION );
		}
		$creado = array( 'usuarios' => array(), 'propiedades' => array(), 'contactos' => array(), 'adjuntos' => array() );

		// Corredores ficticios (contraseñas solo para el entorno de prueba).
		$corredores = array(
			array( 'camila.rojas', 'Camila Rojas (ficticia)', 'camila.rojas@example.test' ),
			array( 'diego.munoz', 'Diego Muñoz (ficticio)', 'diego.munoz@example.test' ),
		);
		$ids_corredores = array();
		foreach ( $corredores as $c ) {
			$u = get_user_by( 'login', $c[0] );
			if ( ! $u ) {
				$uid = wp_insert_user(
					array(
						'user_login'   => $c[0],
						'user_pass'    => 'Prueba.Corredor.2026',
						'user_email'   => $c[2],
						'display_name' => $c[1],
						'role'         => Inmo_Permisos::ROL_CORREDOR,
					)
				);
				if ( ! is_wp_error( $uid ) ) {
					$creado['usuarios'][] = $uid;
				}
			} else {
				$uid = $u->ID;
			}
			$ids_corredores[] = (int) $uid;
		}
		$camila = $ids_corredores[0];

		// Propietaria ficticia.
		$rut     = '15432198-' . Inmo_Util::dv_rut( '15432198' );
		$duena   = Inmo_Contactos::upsert(
			array(
				'nombre'         => 'María Fernanda Soto Paredes (ficticia)',
				'email'          => 'mf.soto@example.test',
				'telefono'       => '+56 9 5555 0101',
				'rut'            => $rut,
				'roles'          => array( 'propietario' ),
				'origen'         => 'referido',
				'corredor_id'    => $camila,
				'consentimiento' => true,
			)
		);
		$creado['contactos'][] = $duena;

		$props = array(
			array(
				'titulo'       => 'Casa mediterránea con jardín en La Reina',
				'descripcion'  => "Casa de dos pisos en calle tranquila, a pasos de plaza y colegios. Living-comedor con salida a terraza, cocina integrada y jardín con árboles frutales.\n\nTexto de ejemplo para pruebas. Inmueble ficticio.",
				'tipo'         => 'casa',
				'operacion'    => 'venta',
				'comuna'       => 'La Reina',
				'sector'       => 'Sector Plaza Egaña',
				'precio'       => 5200,
				'moneda'       => 'UF',
				'contribuciones' => 210000,
				'dormitorios'  => 4,
				'banos'        => 3,
				'estacionamientos' => 2,
				'bodegas'      => 1,
				'sup_util'     => 165,
				'sup_construida' => 180,
				'sup_terreno'  => 450,
				'orientacion'  => 'N',
				'anio'         => 1998,
				'equipamiento' => array( 'jardin', 'quincho', 'calefaccion', 'termopanel', 'logia' ),
				'destacado'    => 1,
				'precision'    => 'aproximada',
				'privado'      => array( 'direccion' => 'Calle Ficticia 1234', 'rol_sii' => '01234-056', 'lat' => -33.4485, 'lng' => -70.5535, 'mandato_tipo' => 'corretaje', 'exclusividad' => 'si', 'comision_pct' => 2, 'comision_detalle' => '2% + IVA a cada parte', 'captacion_vence' => gmdate( 'Y-m-d', strtotime( '+120 days' ) ), 'precio_minimo' => 5000, 'notas' => 'Propietaria prefiere visitas en la tarde. (Dato de prueba)' ),
				'colores'      => array( array( 196, 120, 80 ), array( 120, 160, 200 ) ),
			),
			array(
				'titulo'       => 'Departamento luminoso a pasos del metro en Providencia',
				'descripcion'  => "Departamento de 2 dormitorios y 2 baños con vista despejada, cocina americana y logia. Edificio con conserjería 24 h, piscina y gimnasio. A tres cuadras del metro.\n\nTexto de ejemplo para pruebas. Inmueble ficticio.",
				'tipo'         => 'departamento',
				'operacion'    => 'arriendo',
				'comuna'       => 'Providencia',
				'sector'       => 'Barrio Bellavista / Pedro de Valdivia',
				'precio'       => 650000,
				'moneda'       => 'CLP',
				'gastos_comunes' => 120000,
				'garantia_meses' => 1,
				'reajuste'     => 'ipc_semestral',
				'vigencia_meses' => 12,
				'disponible_desde' => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
				'amoblado'     => 'no',
				'mascotas'     => 'consultar',
				'dormitorios'  => 2,
				'banos'        => 2,
				'estacionamientos' => 1,
				'bodegas'      => 1,
				'piso'         => 9,
				'sup_util'     => 68,
				'sup_terraza'  => 8,
				'orientacion'  => 'NO',
				'anio'         => 2016,
				'equipamiento' => array( 'conserjeria', 'ascensor', 'piscina', 'gimnasio', 'logia', 'termopanel' ),
				'destacado'    => 1,
				'precision'    => 'aproximada',
				'privado'      => array( 'direccion' => 'Av. Imaginaria 2050', 'unidad' => 'Depto 904', 'rol_sii' => '00987-123', 'lat' => -33.4262, 'lng' => -70.6129, 'mandato_tipo' => 'corretaje_admin', 'exclusividad' => 'no', 'comision_pct' => 50, 'comision_detalle' => '50% de un mes de renta + IVA', 'captacion_vence' => gmdate( 'Y-m-d', strtotime( '+60 days' ) ), 'notas' => 'Llaves en conserjería. (Dato de prueba)' ),
				'colores'      => array( array( 90, 110, 140 ), array( 230, 170, 110 ) ),
			),
		);

		foreach ( $props as $i => $p ) {
			$p['comuna_id'] = Inmo_Propiedades::comuna_por_nombre( $p['comuna'] );
			$pid            = Inmo_Propiedades::crear_captacion( $p, 'borrador', $camila );
			if ( is_wp_error( $pid ) ) {
				continue;
			}
			update_post_meta( $pid, '_inmo_demo', 1 );
			Inmo_Privado::guardar( $pid, $p['privado'] );
			Inmo_Propiedades::actualizar_ubicacion_publica( $pid );
			Inmo_Contactos::vincular_titular( $pid, $duena );

			$fotos = array();
			foreach ( array( 'fachada', 'living', 'vista' ) as $j => $escena ) {
				$att = self::imagen( $pid, $p['tipo'], $escena, $p['colores'], $i * 10 + $j );
				if ( $att ) {
					$fotos[]              = $att;
					$creado['adjuntos'][] = $att;
				}
			}
			Inmo_Propiedades::guardar_galeria( $pid, $fotos );
			self::mandato_muestra( $pid, $duena );

			// Aprobación (como administración).
			$r = Inmo_Propiedades::cambiar_estado( $pid, 'disponible', 'publica', self::admin_id() );
			$creado['propiedades'][] = $pid;
		}
		update_option( self::OPCION, $creado, false );
		flush_rewrite_rules( false );
		return $creado;
	}

	private static function admin_id() {
		$a = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		return $a ? (int) $a[0] : 1;
	}

	/** Mandato de MUESTRA (PDF mínimo generado localmente) guardado en el almacenamiento privado. */
	private static function mandato_muestra( $pid, $contacto_id ) {
		$codigo = get_post_meta( $pid, '_inmo_codigo', true );
		$texto  = 'DOCUMENTO DE MUESTRA - Mandato de corretaje ' . $codigo . ' - Sin validez legal';
		$stream = "BT /F1 14 Tf 60 760 Td ({$texto}) Tj ET";
		$objs   = array(
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			'<< /Length ' . strlen( $stream ) . " >>\nstream\n{$stream}\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		);
		$pdf  = "%PDF-1.4\n";
		$offs = array();
		foreach ( $objs as $n => $o ) {
			$offs[] = strlen( $pdf );
			$pdf   .= ( $n + 1 ) . " 0 obj\n{$o}\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offs as $o ) {
			$pdf .= sprintf( "%010d 00000 n \n", $o );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objs ) + 1 ) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

		$sub = gmdate( 'Y/m' );
		$dir = Inmo_Documentos::directorio() . '/' . $sub;
		wp_mkdir_p( $dir );
		$nombre = bin2hex( random_bytes( 16 ) ) . '.pdf';
		file_put_contents( $dir . '/' . $nombre, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return Inmo_Documentos::registrar( $pid, 'mandato', $sub . '/' . $nombre, 'mandato-muestra-' . strtolower( $codigo ) . '.pdf', 'application/pdf', $contacto_id );
	}

	/** Genera una imagen ilustrativa (no fotográfica) y la agrega a la biblioteca de medios. */
	private static function imagen( $pid, $tipo, $escena, array $colores, $semilla ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return 0;
		}
		mt_srand( 1000 + $semilla ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$w   = 1200;
		$h   = 800;
		$img = imagecreatetruecolor( $w, $h );
		$cielo = 'vista' === $escena ? array( 250, 190, 140 ) : array( 170, 205, 235 );
		for ( $y = 0; $y < $h; $y++ ) {
			$t = $y / $h;
			$c = imagecolorallocate( $img, (int) ( $cielo[0] * ( 1 - $t ) + 245 * $t ), (int) ( $cielo[1] * ( 1 - $t ) + 240 * $t ), (int) ( $cielo[2] * ( 1 - $t ) + 230 * $t ) );
			imageline( $img, 0, $y, $w, $y, $c );
		}
		// Cordillera.
		$cord = imagecolorallocate( $img, 120, 130, 150 );
		$niev = imagecolorallocate( $img, 245, 245, 250 );
		$pts  = array( 0, 520 );
		for ( $x = 0; $x <= $w; $x += 100 ) {
			$pts[] = $x;
			$pts[] = 300 + mt_rand( 0, 140 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$pts[] = $w;
		$pts[] = 520;
		self::poligono( $img, $pts, $cord );
		for ( $k = 2; $k < count( $pts ) - 2; $k += 2 ) {
			if ( $pts[ $k + 1 ] < 360 ) {
				imagefilledellipse( $img, $pts[ $k ], $pts[ $k + 1 ] + 12, 60, 26, $niev );
			}
		}
		$suelo = imagecolorallocate( $img, 110, 150, 90 );
		imagefilledrectangle( $img, 0, 520, $w, $h, $suelo );

		$muro  = imagecolorallocate( $img, $colores[0][0], $colores[0][1], $colores[0][2] );
		$acen  = imagecolorallocate( $img, $colores[1][0], $colores[1][1], $colores[1][2] );
		$vidr  = imagecolorallocate( $img, 200, 225, 240 );
		$oscu  = imagecolorallocate( $img, 60, 60, 70 );
		$off   = 'living' === $escena ? -150 : ( 'vista' === $escena ? 150 : 0 );
		if ( 'departamento' === $tipo || 'oficina' === $tipo ) {
			$x0 = 420 + $off;
			imagefilledrectangle( $img, $x0, 140, $x0 + 360, 640, $muro );
			for ( $f = 0; $f < 9; $f++ ) {
				for ( $c = 0; $c < 4; $c++ ) {
					imagefilledrectangle( $img, $x0 + 25 + $c * 85, 160 + $f * 52, $x0 + 85 + $c * 85, 195 + $f * 52, ( 3 === $f ) ? $acen : $vidr );
				}
			}
			imagefilledrectangle( $img, $x0 + 150, 590, $x0 + 210, 640, $oscu );
		} else {
			$x0 = 360 + $off;
			imagefilledrectangle( $img, $x0, 380, $x0 + 480, 640, $muro );
			self::poligono( $img, array( $x0 - 30, 390, $x0 + 240, 250, $x0 + 510, 390 ), $acen );
			imagefilledrectangle( $img, $x0 + 40, 440, $x0 + 160, 530, $vidr );
			imagefilledrectangle( $img, $x0 + 320, 440, $x0 + 440, 530, $vidr );
			imagefilledrectangle( $img, $x0 + 210, 520, $x0 + 270, 640, $oscu );
			$arbol = imagecolorallocate( $img, 60, 110, 60 );
			imagefilledellipse( $img, $x0 - 120, 520, 160, 200, $arbol );
			imagefilledrectangle( $img, $x0 - 128, 600, $x0 - 112, 660, $oscu );
		}
		// Rótulo.
		$blanco = imagecolorallocatealpha( $img, 255, 255, 255, 30 );
		imagefilledrectangle( $img, 20, $h - 60, 420, $h - 20, $blanco );
		imagestring( $img, 5, 34, $h - 48, 'Imagen ilustrativa - dato de prueba', $oscu );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( 'inmo-demo.jpg' );
		imagejpeg( $img, $tmp, 82 );
		imagedestroy( $img );
		$codigo = strtolower( get_post_meta( $pid, '_inmo_codigo', true ) );
		$att    = media_handle_sideload( array( 'name' => "{$codigo}-{$escena}.jpg", 'tmp_name' => $tmp ), $pid, 'Imagen ilustrativa ' . $escena );
		if ( is_wp_error( $att ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return 0;
		}
		update_post_meta( $att, '_wp_attachment_image_alt', 'Imagen ilustrativa de ' . Inmo_Esquema::TIPOS[ $tipo ] . ' (' . $escena . ')' );
		update_post_meta( $att, '_inmo_demo', 1 );
		return (int) $att;
	}

	private static function poligono( $img, array $pts, $color ) {
		if ( PHP_VERSION_ID >= 80000 ) {
			imagefilledpolygon( $img, $pts, $color );
		} else {
			imagefilledpolygon( $img, $pts, count( $pts ) / 2, $color ); // phpcs:ignore PHPCompatibility
		}
	}

	/** Elimina todo lo creado por cargar() y lo que tenga la marca _inmo_demo. */
	public static function eliminar() {
		global $wpdb;
		$d = get_option( self::OPCION );
		if ( ! is_array( $d ) ) {
			return;
		}
		foreach ( (array) $d['propiedades'] as $pid ) {
			foreach ( Inmo_Documentos::de_propiedad( $pid ) as $doc ) {
				$abs = Inmo_Documentos::ruta_absoluta( $doc );
				if ( $abs ) {
					wp_delete_file( $abs );
				}
				$wpdb->delete( Inmo_Documentos::tabla(), array( 'id' => $doc->id ) );
			}
			$wpdb->delete( Inmo_Contactos::tabla_relacion(), array( 'propiedad_id' => $pid ) );
			$wpdb->delete( Inmo_Consultas::tabla(), array( 'propiedad_id' => $pid ) );
			$wpdb->delete( Inmo_Historial::tabla(), array( 'propiedad_id' => $pid ) );
			wp_delete_post( $pid, true );
		}
		foreach ( (array) $d['adjuntos'] as $a ) {
			wp_delete_attachment( $a, true );
		}
		foreach ( (array) $d['contactos'] as $c ) {
			$wpdb->delete( Inmo_Contactos::tabla(), array( 'id' => $c ) );
			$wpdb->delete( Inmo_Historial::tabla(), array( 'contacto_id' => $c ) );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( (array) $d['usuarios'] as $u ) {
			wp_delete_user( $u );
		}
		delete_option( self::OPCION );
	}
}
