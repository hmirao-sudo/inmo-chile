<?php
/**
 * Admin: calculadora de valorización, comparables de mercado (manual / CSV) y caja «Valorización» en la ficha.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Admin_Valorizacion {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 14 );
		add_action( 'admin_post_inmo_comparables', array( __CLASS__, 'procesar' ) );
		add_action( 'add_meta_boxes_' . INMO_CPT, array( __CLASS__, 'meta_box' ) );
	}

	public static function menu() {
		add_submenu_page( 'inmo', 'Valorización', 'Valorización', 'inmo_gestionar', 'inmo-valorizacion', array( __CLASS__, 'pagina' ) );
	}

	public static function meta_box( $post ) {
		if ( 'auto-draft' !== $post->post_status ) {
			add_meta_box( 'inmo_valorizacion', 'Valorización automática', array( __CLASS__, 'mb' ), INMO_CPT, 'side', 'default' );
		}
	}

	public static function mb( $post ) {
		$r = Inmo_Valorizacion::de_propiedad( $post->ID );
		if ( ! $r['suficiente'] ) {
			echo '<p>' . esc_html( $r['motivo'] ?? 'Sin datos suficientes.' ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=inmo-valorizacion' ) ) . '">Cargar comparables de mercado</a></p>';
			return;
		}
		$precio = get_post_meta( $post->ID, '_inmo_precio', true );
		$mon    = get_post_meta( $post->ID, '_inmo_moneda', true );
		echo '<p class="inmo-val-rango" id="inmo-val-rango"><strong>' . esc_html( Inmo_Valorizacion::texto_rango( $r ) ) . '</strong><br><span class="description">Valor central ' . esc_html( Inmo_Util::formatear_monto( $r['valor'], $r['moneda'] ) ) . ' · ' . esc_html( Inmo_Util::formatear_monto( $r['valor_m2'], $r['moneda'] ) ) . '/m²</span></p>';
		echo '<p>Confianza <strong>' . esc_html( Inmo_Valorizacion::CONFIANZA[ $r['confianza'] ] ) . '</strong> · ' . (int) $r['n'] . ' comparables en ' . esc_html( $r['ambito'] ) . ' · dispersión ' . esc_html( number_format( $r['dispersion'], 1, ',', '' ) ) . '%</p>';
		if ( '' !== (string) $precio ) {
			$p = (float) $precio;
			if ( $mon !== $r['moneda'] ) {
				$uf = Inmo_Ajustes::uf();
				$p  = 'UF' === $r['moneda'] ? $p / $uf : $p * $uf;
			}
			$dif = $r['valor'] > 0 ? ( $p - $r['valor'] ) / $r['valor'] * 100 : 0;
			$pos = $p < $r['min'] ? 'bajo el rango' : ( $p > $r['max'] ? 'sobre el rango' : 'dentro del rango' );
			echo '<p id="inmo-val-comparacion">Precio publicado ' . esc_html( Inmo_Util::formatear_monto( $precio, $mon ) ) . ': <strong>' . esc_html( $pos ) . '</strong> (' . esc_html( ( $dif >= 0 ? '+' : '' ) . number_format( $dif, 1, ',', '' ) ) . '% respecto del valor central).</p>';
		}
		echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => 'inmo-valorizacion', 'propiedad' => $post->ID ), admin_url( 'admin.php' ) ) ) . '">Ver comparables y detalle</a></p>';
	}

	private static function tabla_resultado( array $r ) {
		echo '<div class="inmo-caja" id="inmo-val-resultado">';
		if ( ! $r['suficiente'] ) {
			echo '<p><strong>Sin datos suficientes.</strong> ' . esc_html( $r['motivo'] ?? '' ) . '</p></div>';
			return;
		}
		echo '<h2>' . esc_html( Inmo_Valorizacion::texto_rango( $r ) ) . '</h2>';
		echo '<p>Valor central <strong>' . esc_html( Inmo_Util::formatear_monto( $r['valor'], $r['moneda'] ) ) . '</strong> · ' . esc_html( Inmo_Util::formatear_monto( $r['valor_m2'], $r['moneda'] ) ) . '/m² · confianza <strong>' . esc_html( Inmo_Valorizacion::CONFIANZA[ $r['confianza'] ] ) . '</strong> · dispersión ' . esc_html( number_format( $r['dispersion'], 1, ',', '' ) ) . '%' . ( $r['ajuste'] ? ' · ajuste por estacionamientos ' . esc_html( Inmo_Util::formatear_monto( $r['ajuste'], $r['moneda'] ) ) : '' ) . '</p>';
		echo '<p class="description">' . esc_html( $r['explicacion'] ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>Origen</th><th>Fecha</th><th class="num">m²</th><th class="num">Precio</th><th class="num">' . esc_html( $r['moneda'] ) . '/m²</th><th class="num">Peso</th><th>Fuente</th></tr></thead><tbody>';
		foreach ( $r['comparables'] as $c ) {
			$ref = 'catalogo' === $c['origen'] ? '<a href="' . esc_url( get_edit_post_link( $c['id'] ) ) . '">' . esc_html( $c['codigo'] ) . '</a>' : ( $c['url'] ? '<a href="' . esc_url( $c['url'] ) . '" target="_blank" rel="noopener">Mercado</a>' : 'Mercado' );
			echo '<tr><td>' . $ref . '</td><td>' . esc_html( Inmo_Util::fecha( $c['fecha'] ) ) . '</td><td class="num">' . esc_html( number_format( $c['superficie'], 0, ',', '.' ) ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( round( $c['valor'], 'UF' === $r['moneda'] ? 0 : 0 ), $r['moneda'] ) ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( round( $c['valor_m2'], 'UF' === $r['moneda'] ? 2 : 0 ), $r['moneda'] ) ) . '</td><td class="num">' . esc_html( number_format( $c['peso'], 2, ',', '' ) ) . '</td><td>' . esc_html( $c['fuente'] . ( $c['es_cierre'] ? ' (cierre)' : '' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}

	public static function pagina() {
		$pid = absint( Inmo_Util::get( 'propiedad' ) );
		echo '<div class="wrap inmo-wrap"><h1>Valorización automática</h1>';
		echo '<p class="description">Estimación estadística por comparables (catálogo propio y comparables de mercado). Es referencial: no reemplaza la visita ni una tasación.</p>';

		// Calculadora.
		$g = array(
			'tipo'             => Inmo_Util::get( 'tipo' ),
			'operacion'        => Inmo_Util::get( 'operacion', 'venta' ),
			'comuna_id'        => absint( Inmo_Util::get( 'comuna_id' ) ),
			'superficie'       => Inmo_Util::get( 'superficie' ),
			'dormitorios'      => Inmo_Util::get( 'dormitorios' ),
			'estacionamientos' => Inmo_Util::get( 'estacionamientos' ),
		);
		echo '<h2>Calculadora</h2><form method="get" class="inmo-grid inmo-val-form"><input type="hidden" name="page" value="inmo-valorizacion">';
		echo '<p><label for="v-tipo">Tipo</label><select id="v-tipo" name="tipo">';
		foreach ( Inmo_Esquema::TIPOS as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $g['tipo'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p><p><label for="v-op">Operación</label><select id="v-op" name="operacion">';
		foreach ( Inmo_Esquema::OPERACIONES as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $g['operacion'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p><p><label for="v-com">Comuna</label><select id="v-com" name="comuna_id"><option value="">—</option>';
		foreach ( Inmo_Busqueda::comunas( false, true ) as $reg => $cs ) {
			echo '<optgroup label="' . esc_attr( $reg ) . '">';
			foreach ( $cs as $id => $n ) {
				echo '<option value="' . (int) $id . '"' . selected( $g['comuna_id'], $id, false ) . '>' . esc_html( $n ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select></p>';
		foreach ( array( 'superficie' => 'Superficie (m²)', 'dormitorios' => 'Dormitorios', 'estacionamientos' => 'Estacionamientos' ) as $k => $l ) {
			echo '<p><label for="v-' . esc_attr( $k ) . '">' . esc_html( $l ) . '</label><input type="text" inputmode="decimal" id="v-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" value="' . esc_attr( $g[ $k ] ) . '"></p>';
		}
		echo '<p><button class="button button-primary">Estimar</button></p></form>';

		if ( $pid && Inmo_Permisos::puede_ver_privado( $pid ) ) {
			echo '<h2>Propiedad ' . esc_html( get_post_meta( $pid, '_inmo_codigo', true ) . ' — ' . get_the_title( $pid ) ) . '</h2>';
			self::tabla_resultado( Inmo_Valorizacion::de_propiedad( $pid ) );
		} elseif ( $g['comuna_id'] && '' !== $g['superficie'] ) {
			$g['superficie'] = Inmo_Util::parse_numero( $g['superficie'] );
			$r               = Inmo_Valorizacion::estimar( $g );
			Inmo_Valorizacion::registrar( $r, 0, 0, $g );
			self::tabla_resultado( $r );
		}

		// Comparables de mercado.
		echo '<h2>Comparables de mercado</h2>';
		echo '<p class="description">Ventas y arriendos observados en portales, tasaciones o cierres de colegas. Ingresa precios reales (o publicados, marcando si es cierre). Columnas CSV: <code>' . esc_html( implode( ',', Inmo_Valorizacion::columnas_csv() ) ) . '</code> · fecha AAAA-MM-DD, tipo (departamento, casa, oficina, local, terreno), operación (venta, arriendo), moneda (UF o CLP), es_cierre (si/no).</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="inmo-ctrl"><input type="hidden" name="action" value="inmo_comparables"><input type="hidden" name="op" value="csv">';
		wp_nonce_field( 'inmo_comparables' );
		echo '<label for="v-csv">Importar CSV</label> <input type="file" id="v-csv" name="csv" accept=".csv,text/csv" required> <button class="button">Importar</button></form>';

		echo '<details><summary>Agregar un comparable</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="inmo-grid"><input type="hidden" name="action" value="inmo_comparables"><input type="hidden" name="op" value="nuevo">';
		wp_nonce_field( 'inmo_comparables' );
		foreach ( array( 'fecha' => 'Fecha', 'tipo' => 'Tipo', 'operacion' => 'Operación', 'comuna' => 'Comuna', 'superficie' => 'm²', 'dormitorios' => 'Dormitorios', 'estacionamientos' => 'Estacionamientos', 'precio' => 'Precio', 'moneda' => 'Moneda (UF/CLP)', 'es_cierre' => '¿Cierre? (si/no)', 'fuente' => 'Fuente', 'url' => 'URL' ) as $k => $l ) {
			echo '<p><label for="n-' . esc_attr( $k ) . '">' . esc_html( $l ) . '</label><input type="' . ( 'fecha' === $k ? 'date' : 'text' ) . '" id="n-' . esc_attr( $k ) . '" name="c[' . esc_attr( $k ) . ']"></p>';
		}
		echo '<p><button class="button">Agregar</button></p></form></details>';

		$rows = Inmo_Valorizacion::listar_comparables();
		if ( $rows ) {
			echo '<table class="widefat striped" id="inmo-comparables"><thead><tr><th>Fecha</th><th>Tipo</th><th>Operación</th><th>Comuna</th><th class="num">m²</th><th>Dorm.</th><th class="num">Precio</th><th>Dato</th><th>Fuente</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $c ) {
				$com = get_term( (int) $c->comuna_id );
				echo '<tr><td>' . esc_html( Inmo_Util::fecha( $c->fecha ) ) . '</td><td>' . esc_html( Inmo_Esquema::TIPOS[ $c->tipo ] ?? $c->tipo ) . '</td><td>' . esc_html( Inmo_Esquema::OPERACIONES[ $c->operacion ] ?? $c->operacion ) . '</td><td>' . esc_html( $com && ! is_wp_error( $com ) ? $com->name : '' ) . '</td><td class="num">' . esc_html( number_format( (float) $c->superficie, 0, ',', '.' ) ) . '</td><td>' . esc_html( (string) $c->dormitorios ) . '</td><td class="num">' . esc_html( Inmo_Util::formatear_monto( $c->precio, $c->moneda ) ) . '</td><td>' . ( $c->es_cierre ? 'Cierre' : 'Publicado' ) . '</td><td>' . esc_html( $c->fuente ) . '</td><td>';
				if ( current_user_can( 'inmo_ajustes' ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="inmo_comparables"><input type="hidden" name="op" value="borrar"><input type="hidden" name="id" value="' . (int) $c->id . '">';
					wp_nonce_field( 'inmo_comparables' );
					echo '<button class="button-link-delete" aria-label="Eliminar comparable">Eliminar</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Aún no hay comparables de mercado. La valorización usará solo el catálogo propio.</p>';
		}
		echo '</div>';
	}

	public static function procesar() {
		check_admin_referer( 'inmo_comparables' );
		if ( ! current_user_can( 'inmo_gestionar' ) ) {
			wp_die( 'Sin permiso.', '', array( 'response' => 403 ) );
		}
		$op = Inmo_Util::post( 'op' );
		if ( 'csv' === $op ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$f = $_FILES['csv'] ?? null;
			if ( ! $f || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) || $f['size'] > 2 * MB_IN_BYTES ) {
				Inmo_Admin::avisar( 'error', 'Sube un archivo CSV de hasta 2 MB.' );
			} else {
				list( $n, $err ) = Inmo_Valorizacion::importar_csv( $f['tmp_name'] );
				Inmo_Admin::avisar( $err ? 'warning' : 'success', esc_html( $n . ' comparable(s) importado(s).' ) . ( $err ? '<br>' . implode( '<br>', array_map( 'esc_html', array_slice( $err, 0, 20 ) ) ) : '' ) );
			}
		} elseif ( 'nuevo' === $op ) {
			$c = isset( $_POST['c'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['c'] ) ) : array();
			$r = Inmo_Valorizacion::agregar_comparable( $c );
			Inmo_Admin::avisar( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? esc_html( $r->get_error_message() ) : 'Comparable agregado.' );
		} elseif ( 'borrar' === $op && current_user_can( 'inmo_ajustes' ) ) {
			Inmo_Valorizacion::borrar_comparable( absint( Inmo_Util::post( 'id' ) ) );
			Inmo_Admin::avisar( 'success', 'Comparable eliminado.' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=inmo-valorizacion' ) );
		exit;
	}
}
