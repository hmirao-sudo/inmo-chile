<?php
/**
 * Documentos privados (mandatos, antecedentes, fotos enviadas por el propietario pendientes de autorización).
 * Se guardan FUERA de la biblioteca de medios, con nombres aleatorios, en un directorio protegido,
 * y solo se entregan mediante un endpoint que verifica permisos por propiedad.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Documentos {

	const TIPOS = array(
		'mandato'          => 'Mandato / orden de corretaje',
		'contrato'         => 'Documento emitido (PDF)',
		'firmado'          => 'Documento firmado',
		'antecedente'      => 'Antecedente legal',
		'escritura'        => 'Escritura de compraventa',
		'foto_propietario' => 'Foto enviada por el propietario',
		'otro'             => 'Otro',
	);

	const MIME_DOCS  = array( 'pdf' => 'application/pdf', 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
	const MIME_FOTOS = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
	const MAX_BYTES  = 10485760; // 10 MB.

	public static function init() {
		add_action( 'admin_post_inmo_documento', array( __CLASS__, 'descargar' ) );
	}

	public static function tabla() {
		global $wpdb;
		return $wpdb->prefix . 'inmo_documentos';
	}

	/** Directorio privado. Puede definirse INMO_DIR_PRIVADO en wp-config.php (recomendado: fuera del webroot). */
	public static function directorio() {
		if ( defined( 'INMO_DIR_PRIVADO' ) && INMO_DIR_PRIVADO ) {
			$dir = untrailingslashit( INMO_DIR_PRIVADO );
		} else {
			$up  = wp_upload_dir( null, false );
			$dir = $up['basedir'] . '/inmo-privado';
		}
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::proteger( $dir );
		return $dir;
	}

	private static function proteger( $dir ) {
		$archivos = array(
			'.htaccess'  => "# Inmo Chile: acceso directo prohibido\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silencio.\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $archivos as $n => $c ) {
			if ( ! file_exists( $dir . '/' . $n ) ) {
				file_put_contents( $dir . '/' . $n, $c ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Guarda un archivo subido ($_FILES item) como documento privado.
	 *
	 * @return int|WP_Error ID del documento.
	 */
	public static function guardar_subida( array $file, $propiedad_id, $tipo, $contacto_id = 0, $solo_imagenes = false, $subtipo = '' ) {
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'inmo_subida', 'No se pudo recibir el archivo (código ' . (int) $file['error'] . ').' );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			if ( ! ( defined( 'INMO_PRUEBAS' ) && INMO_PRUEBAS && ! empty( $file['tmp_name'] ) && file_exists( $file['tmp_name'] ) ) ) {
				return new WP_Error( 'inmo_subida', 'Archivo no válido.' );
			}
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'inmo_tamano', 'El archivo supera los 10 MB.' );
		}
		$permitidos = $solo_imagenes ? self::MIME_FOTOS : self::MIME_DOCS;
		$check      = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $permitidos );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new WP_Error( 'inmo_tipo', 'Tipo de archivo no permitido.' );
		}
		if ( $solo_imagenes && ! @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'inmo_tipo', 'La imagen no es válida.' );
		}
		$sub = gmdate( 'Y/m' );
		$dir = self::directorio() . '/' . $sub;
		wp_mkdir_p( $dir );
		$nombre  = bin2hex( random_bytes( 16 ) ) . '.' . $check['ext'];
		$destino = $dir . '/' . $nombre;
		$ok      = is_uploaded_file( $file['tmp_name'] ) ? move_uploaded_file( $file['tmp_name'], $destino ) : copy( $file['tmp_name'], $destino );
		if ( ! $ok ) {
			return new WP_Error( 'inmo_mover', 'No se pudo guardar el archivo.' );
		}
		return self::registrar( $propiedad_id, $tipo, $sub . '/' . $nombre, sanitize_file_name( $file['name'] ), $check['type'], $contacto_id, $subtipo );
	}

	public static function registrar( $propiedad_id, $tipo, $ruta_rel, $nombre, $mime, $contacto_id = 0, $subtipo = '' ) {
		global $wpdb;
		$abs = self::directorio() . '/' . $ruta_rel;
		$wpdb->insert(
			self::tabla(),
			array(
				'propiedad_id' => (int) $propiedad_id,
				'contacto_id'  => (int) $contacto_id,
				'tipo'         => isset( self::TIPOS[ $tipo ] ) ? $tipo : 'otro',
				'subtipo'      => sanitize_key( $subtipo ),
				'nombre'       => $nombre,
				'ruta'         => $ruta_rel,
				'mime'         => $mime,
				'bytes'        => (int) filesize( $abs ),
				'sha256'       => hash_file( 'sha256', $abs ),
				'estado'       => 'foto_propietario' === $tipo ? 'pendiente' : 'vigente',
				'subido_por'   => get_current_user_id(),
				'creado'       => Inmo_Util::ahora(),
			)
		);
		$id = (int) $wpdb->insert_id;
		Inmo_Historial::registrar( 'documento', self::TIPOS[ isset( self::TIPOS[ $tipo ] ) ? $tipo : 'otro' ] . ': ' . $nombre, array( 'propiedad_id' => $propiedad_id, 'contacto_id' => $contacto_id ) );
		return $id;
	}

	/** Guarda contenido generado (p. ej. un PDF emitido) en el almacenamiento privado. */
	public static function guardar_contenido( $contenido, $ext, $propiedad_id, $tipo, $nombre, $mime, $contacto_id = 0, $subtipo = '' ) {
		$sub = gmdate( 'Y/m' );
		$dir = self::directorio() . '/' . $sub;
		wp_mkdir_p( $dir );
		$archivo = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		if ( false === file_put_contents( $dir . '/' . $archivo, $contenido ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'inmo_guardar', 'No se pudo guardar el archivo.' );
		}
		return self::registrar( $propiedad_id, $tipo, $sub . '/' . $archivo, $nombre, $mime, $contacto_id, $subtipo );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::tabla() . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	public static function de_propiedad( $propiedad_id, $tipo = null, $estado = null ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::tabla() . ' WHERE propiedad_id = %d';
		$p   = array( $propiedad_id );
		if ( $tipo ) {
			$sql .= ' AND tipo = %s';
			$p[]  = $tipo;
		}
		if ( $estado ) {
			$sql .= ' AND estado = %s';
			$p[]  = $estado;
		}
		return $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id DESC', $p ) ); // phpcs:ignore
	}

	public static function marcar( $id, $estado ) {
		global $wpdb;
		$wpdb->update( self::tabla(), array( 'estado' => sanitize_key( $estado ) ), array( 'id' => (int) $id ) );
	}

	public static function ruta_absoluta( $doc ) {
		$base = realpath( self::directorio() );
		$abs  = realpath( self::directorio() . '/' . $doc->ruta );
		return ( $base && $abs && 0 === strpos( $abs, $base ) ) ? $abs : '';
	}

	/** URL de descarga con nonce (solo útil para usuarios con permiso). */
	public static function url( $doc_id, $inline = false ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=inmo_documento&id=' . (int) $doc_id . ( $inline ? '&inline=1' : '' ) ), 'inmo_documento_' . (int) $doc_id );
	}

	/** ¿Puede el usuario actual abrir el documento? */
	public static function puede_abrir( $doc, $user_id = null ) {
		return $doc && Inmo_Permisos::puede_ver_privado( (int) $doc->propiedad_id, $user_id );
	}

	/** Endpoint admin-post.php?action=inmo_documento */
	public static function descargar() {
		$id  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'inmo_documento_' . $id );
		$doc = self::get( $id );
		if ( ! self::puede_abrir( $doc ) ) {
			Inmo_Historial::registrar( 'documento', 'Acceso denegado al documento #' . $id, array( 'propiedad_id' => $doc ? $doc->propiedad_id : 0 ) );
			wp_die( 'No tienes permiso para abrir este documento.', 'Acceso denegado', array( 'response' => 403 ) );
		}
		$abs = self::ruta_absoluta( $doc );
		if ( ! $abs || ! is_readable( $abs ) ) {
			wp_die( 'Archivo no encontrado.', '', array( 'response' => 404 ) );
		}
		$inline = ! empty( $_GET['inline'] ) && 0 === strpos( $doc->mime, 'image/' );
		nocache_headers();
		header( 'Content-Type: ' . $doc->mime );
		header( 'Content-Length: ' . filesize( $abs ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . rawurlencode( $doc->nombre ) . '"' );
		readfile( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Copia una foto privada autorizada a la biblioteca de medios y la agrega a la galería. */
	public static function autorizar_foto( $doc_id, $propiedad_id ) {
		$doc = self::get( $doc_id );
		if ( ! $doc || 'foto_propietario' !== $doc->tipo || (int) $doc->propiedad_id !== (int) $propiedad_id || 'pendiente' !== $doc->estado ) {
			return new WP_Error( 'inmo_foto', 'Foto no válida.' );
		}
		$abs = self::ruta_absoluta( $doc );
		if ( ! $abs ) {
			return new WP_Error( 'inmo_foto', 'Archivo no encontrado.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( $doc->nombre );
		copy( $abs, $tmp );
		$codigo = get_post_meta( $propiedad_id, '_inmo_codigo', true );
		$ext    = pathinfo( $doc->ruta, PATHINFO_EXTENSION );
		$att    = media_handle_sideload(
			array( 'name' => sanitize_file_name( strtolower( $codigo ) . '-' . $doc->id . '.' . $ext ), 'tmp_name' => $tmp ),
			$propiedad_id,
			get_the_title( $propiedad_id )
		);
		if ( is_wp_error( $att ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return $att;
		}
		$gal   = Inmo_Propiedades::galeria( $propiedad_id );
		$gal[] = (int) $att;
		Inmo_Propiedades::guardar_galeria( $propiedad_id, $gal );
		self::marcar( $doc_id, 'autorizada' );
		Inmo_Historial::registrar( 'foto_autorizada', 'Foto del propietario incorporada a la galería', array( 'propiedad_id' => $propiedad_id ) );
		return (int) $att;
	}
}
