<?php
/**
 * Plantillas editables y versionadas (mandato, orden de visita, reserva, arriendo, promesa).
 * Cada guardado que cambia el texto sube la versión; WordPress conserva las revisiones anteriores.
 * Los documentos emitidos guardan una copia completa del texto, por lo que no cambian si luego se edita la plantilla.
 *
 * Variables: {{propiedad.direccion}} es obligatoria; {{aval.nombre?}} (con ?) es opcional.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Plantillas {

	const CPT = 'inmo_plantilla';

	const TIPOS = array(
		'mandato'      => 'Mandato de corretaje',
		'orden_visita' => 'Orden de visita',
		'reserva'      => 'Reserva',
		'arriendo'     => 'Contrato de arriendo',
		'promesa'      => 'Promesa de compraventa',
	);

	/** Contexto que requiere cada tipo. */
	const CONTEXTO = array(
		'mandato'      => 'propiedad',
		'orden_visita' => 'oportunidad',
		'reserva'      => 'oportunidad',
		'arriendo'     => 'oportunidad',
		'promesa'      => 'oportunidad',
	);

	/** Operación a la que aplica cada tipo ('' = ambas). */
	const OPERACION = array(
		'mandato'      => '',
		'orden_visita' => '',
		'reserva'      => '',
		'arriendo'     => 'arriendo',
		'promesa'      => 'venta',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'registrar' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'al_guardar' ), 20, 2 );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'meta_boxes' ) );
		add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'revisiones' ), 10, 2 );
	}

	public static function registrar() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => 'Plantillas',
					'singular_name' => 'Plantilla',
					'add_new'       => 'Nueva plantilla',
					'add_new_item'  => 'Nueva plantilla',
					'edit_item'     => 'Editar plantilla',
					'all_items'     => 'Plantillas de documentos',
					'menu_name'     => 'Plantillas',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'inmo',
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor', 'revisions' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'edit_post'          => 'inmo_ajustes',
					'read_post'          => 'inmo_gestionar',
					'delete_post'        => 'inmo_ajustes',
					'edit_posts'         => 'inmo_ajustes',
					'edit_others_posts'  => 'inmo_ajustes',
					'publish_posts'      => 'inmo_ajustes',
					'read_private_posts' => 'inmo_ajustes',
					'delete_posts'       => 'inmo_ajustes',
					'create_posts'       => 'inmo_ajustes',
				),
				'map_meta_cap'    => false,
			)
		);
	}

	public static function revisiones( $n, $post ) {
		return self::CPT === $post->post_type ? -1 : $n; // Conservar todas las versiones.
	}

	public static function meta_boxes() {
		add_meta_box( 'inmo_plantilla_tipo', 'Tipo y versión', array( __CLASS__, 'mb_tipo' ), self::CPT, 'side', 'high' );
		add_meta_box( 'inmo_plantilla_vars', 'Variables disponibles', array( __CLASS__, 'mb_variables' ), self::CPT, 'normal', 'default' );
	}

	public static function mb_tipo( $post ) {
		wp_nonce_field( 'inmo_plantilla_' . $post->ID, 'inmo_plantilla_nonce' );
		$tipo = get_post_meta( $post->ID, '_inmo_tipo_doc', true );
		echo '<p><label for="inmo_tipo_doc"><strong>Tipo de documento</strong></label><br><select id="inmo_tipo_doc" name="inmo_tipo_doc" class="widefat">';
		foreach ( self::TIPOS as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $tipo, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p>';
		echo '<p>Versión actual: <strong>' . (int) get_post_meta( $post->ID, '_inmo_version', true ) . '</strong></p>';
		echo '<p class="description">Cada cambio en el texto crea una nueva versión. Los documentos ya emitidos conservan el texto con el que se emitieron. Puedes ver y restaurar versiones anteriores en «Revisiones».</p>';
		if ( get_post_meta( $post->ID, '_inmo_muestra', true ) ) {
			echo '<p class="notice notice-warning inline" style="padding:6px">Texto de <strong>muestra</strong>: reemplázalo por el modelo revisado por tu abogado.</p>';
		}
	}

	public static function mb_variables( $post ) {
		echo '<p>Escribe las variables entre llaves dobles. Toda variable usada es <strong>obligatoria</strong> para emitir; agrega <code>?</code> para que sea opcional, por ejemplo <code>{{aval.nombre?}}</code>.</p><table class="widefat striped"><tbody>';
		foreach ( Inmo_Contratos::variables() as $k => $l ) {
			echo '<tr><td><code>{{' . esc_html( $k ) . '}}</code></td><td>' . esc_html( $l ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function al_guardar( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['inmo_plantilla_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['inmo_plantilla_nonce'] ) ), 'inmo_plantilla_' . $post_id ) ) {
			$tipo = Inmo_Util::post( 'inmo_tipo_doc' );
			if ( isset( self::TIPOS[ $tipo ] ) ) {
				update_post_meta( $post_id, '_inmo_tipo_doc', $tipo );
			}
		}
		$hash = hash( 'sha256', $post->post_title . "\n" . $post->post_content );
		if ( get_post_meta( $post_id, '_inmo_hash', true ) !== $hash ) {
			$v = (int) get_post_meta( $post_id, '_inmo_version', true ) + 1;
			update_post_meta( $post_id, '_inmo_version', $v );
			update_post_meta( $post_id, '_inmo_hash', $hash );
			if ( $v > 1 ) {
				delete_post_meta( $post_id, '_inmo_muestra' );
				Inmo_Historial::registrar( 'plantilla', 'Plantilla «' . $post->post_title . '» actualizada a la versión ' . $v );
			}
		}
	}

	/** Plantillas publicadas de un tipo (o todas). */
	public static function de_tipo( $tipo = '' ) {
		$args = array( 'post_type' => self::CPT, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' );
		if ( $tipo ) {
			$args['meta_key']   = '_inmo_tipo_doc'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_value'] = $tipo; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return get_posts( $args );
	}

	/** Revisión de WordPress que corresponde a la versión actual (trazabilidad). */
	public static function revision_actual( $plantilla_id ) {
		$revs = wp_get_post_revisions( $plantilla_id, array( 'posts_per_page' => 1, 'fields' => 'ids' ) );
		return $revs ? (int) reset( $revs ) : 0;
	}

	/** Crea las plantillas de muestra si no existen. */
	public static function crear_muestras() {
		if ( get_option( 'inmo_plantillas_muestra' ) ) {
			return;
		}
		$aviso = '<p style="border:1px solid #b45309;padding:6px;color:#b45309"><strong>MODELO DE MUESTRA — sin validez legal. Reemplazar por el texto revisado por un abogado antes de usar.</strong></p>';
		$m     = array(
			'mandato'      => array(
				'Mandato de corretaje (muestra)',
				$aviso . '<h1>Mandato de corretaje N° {{documento.numero}}</h1>
<p>En {{corredora.ciudad}}, a {{fecha.hoy}}, don/doña <strong>{{propietario.nombre}}</strong>, RUT {{propietario.rut}}, en adelante «el mandante», encarga a <strong>{{corredora.nombre}}</strong>, en adelante «la corredora», la {{mandato.operacion}} del inmueble ubicado en <strong>{{propiedad.direccion}}</strong>, comuna de {{propiedad.comuna}}, rol SII {{propiedad.rol_sii}}, código interno {{propiedad.codigo}}.</p>
<p><strong>Precio o renta:</strong> {{precio.publicado}} ({{precio.publicado_palabras}}).</p>
<p><strong>Exclusividad:</strong> {{mandato.exclusividad}}. <strong>Comisión:</strong> {{mandato.comision}}. <strong>Vigencia:</strong> hasta el {{mandato.vence}}.</p>
<p>Corredor responsable: {{corredor.nombre}}.</p>
<p style="margin-top:60px">_____________________<br>{{propietario.nombre}}<br>Mandante</p><p>_____________________<br>{{corredora.nombre}}</p>',
			),
			'orden_visita' => array(
				'Orden de visita (muestra)',
				$aviso . '<h1>Orden de visita N° {{documento.numero}}</h1>
<p>{{cliente.nombre}}, RUT {{cliente.rut}}, declara haber visitado con {{corredora.nombre}} el inmueble código {{propiedad.codigo}}, {{propiedad.tipo}} en {{propiedad.comuna}}, el {{visita.fecha}}, ofrecido en {{propiedad.operacion}} por {{precio.publicado}}.</p>
<p>Si la operación se concreta con el propietario o terceros relacionados dentro de los 12 meses siguientes, se devengará la comisión de corretaje.</p>
<p style="margin-top:60px">_____________________<br>{{cliente.nombre}}</p>',
			),
			'reserva'      => array(
				'Reserva (muestra)',
				$aviso . '<h1>Recibo de reserva N° {{documento.numero}}</h1>
<p>{{corredora.nombre}} recibe de {{cliente.nombre}}, RUT {{cliente.rut}}, la suma de <strong>{{reserva.monto}}</strong> ({{reserva.monto_palabras}}) por concepto de reserva del inmueble código {{propiedad.codigo}}, ubicado en {{propiedad.direccion}}, {{propiedad.comuna}}, para su {{propiedad.operacion}} en {{operacion.precio}}.</p>
<p>Fecha de reserva: {{reserva.fecha}}.</p>
<p style="margin-top:60px">_____________________<br>{{cliente.nombre}}</p><p>_____________________<br>{{corredora.nombre}}</p>',
			),
			'arriendo'     => array(
				'Contrato de arriendo (muestra)',
				$aviso . '<h1>Contrato de arrendamiento N° {{documento.numero}}</h1>
<p>En {{corredora.ciudad}}, a {{fecha.hoy}}, entre <strong>{{arrendador.nombre}}</strong>, RUT {{arrendador.rut}}, en adelante «el arrendador», y <strong>{{arrendatario.nombre}}</strong>, RUT {{arrendatario.rut}}, en adelante «el arrendatario», se conviene el siguiente contrato:</p>
<p><strong>Primero. Inmueble.</strong> El arrendador da en arrendamiento al arrendatario el inmueble ubicado en <strong>{{propiedad.direccion}}</strong>, comuna de {{propiedad.comuna}}, {{propiedad.region}}.</p>
<p><strong>Segundo. Renta.</strong> La renta mensual es de <strong>{{arriendo.renta}}</strong> ({{arriendo.renta_palabras}}), pagadera por anticipado dentro de los primeros {{arriendo.dia_pago}} días de cada mes. Se reajustará según: {{arriendo.reajuste}}.</p>
<p><strong>Tercero. Plazo.</strong> El contrato rige desde el {{arriendo.fecha_inicio}} por {{arriendo.plazo_meses}} meses.</p>
<p><strong>Cuarto. Garantía.</strong> El arrendatario entrega una garantía de <strong>{{arriendo.garantia}}</strong> ({{arriendo.garantia_meses}} mes(es) de renta), que se restituirá al término del contrato en los términos de la ley.</p>
<p><strong>Quinto. Gastos comunes.</strong> Son de cargo del arrendatario. Monto aproximado: {{arriendo.gastos_comunes?}}.</p>
<p><strong>Sexto. Aval.</strong> {{aval.nombre?}} {{aval.rut?}}</p>
<p>Intermedia {{corredora.nombre}}, corredor responsable {{corredor.nombre}}.</p>
<p style="margin-top:60px">_____________________<br>{{arrendador.nombre}}<br>Arrendador</p><p>_____________________<br>{{arrendatario.nombre}}<br>Arrendatario</p>',
			),
			'promesa'      => array(
				'Promesa de compraventa (muestra)',
				$aviso . '<h1>Promesa de compraventa N° {{documento.numero}}</h1>
<p>En {{corredora.ciudad}}, a {{fecha.hoy}}, <strong>{{vendedor.nombre}}</strong>, RUT {{vendedor.rut}}, «el promitente vendedor», y <strong>{{comprador.nombre}}</strong>, RUT {{comprador.rut}}, «el promitente comprador», acuerdan:</p>
<p><strong>Primero.</strong> El promitente vendedor promete vender el inmueble ubicado en <strong>{{propiedad.direccion}}</strong>, comuna de {{propiedad.comuna}}, rol SII {{propiedad.rol_sii}}.</p>
<p><strong>Segundo. Precio.</strong> <strong>{{venta.precio}}</strong> ({{venta.precio_palabras}}), que se pagará: pie de {{venta.pie?}} y el saldo {{venta.credito?}}.</p>
<p><strong>Tercero. Escritura.</strong> La compraventa definitiva se otorgará a más tardar el {{venta.fecha_escritura}} en la notaría {{venta.notaria}}.</p>
<p><strong>Cuarto. Reserva.</strong> Se imputa al precio la reserva de {{reserva.monto?}}.</p>
<p style="margin-top:60px">_____________________<br>{{vendedor.nombre}}</p><p>_____________________<br>{{comprador.nombre}}</p>',
			),
		);
		foreach ( $m as $tipo => $d ) {
			$id = wp_insert_post( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $d[0], 'post_content' => $d[1] ) );
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_inmo_tipo_doc', $tipo );
				update_post_meta( $id, '_inmo_muestra', 1 );
				if ( ! get_post_meta( $id, '_inmo_version', true ) ) {
					update_post_meta( $id, '_inmo_version', 1 );
					update_post_meta( $id, '_inmo_hash', hash( 'sha256', $d[0] . "\n" . get_post_field( 'post_content', $id ) ) );
				}
			}
		}
		update_option( 'inmo_plantillas_muestra', 1 );
	}
}
