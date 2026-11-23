<?php
/**
 * Buscador: Comprar / Arrendar, comuna, tipo y rango de precio. Envía por GET → URL compartible.
 *
 * @package InmoVitrina
 */

$f        = (array) get_query_var( 'inmo_filtros', array() );
$variante = get_query_var( 'inmo_variante', 'portada' );
$f        = wp_parse_args( $f, array( 'operacion' => 'venta', 'tipo' => '', 'comuna' => '', 'precio_min' => '', 'precio_max' => '', 'moneda' => '', 'dormitorios' => 0, 'banos' => 0, 'orden' => 'recientes' ) );
$op       = $f['operacion'] ? $f['operacion'] : 'venta';
$moneda   = $f['moneda'] ? $f['moneda'] : Inmo_Esquema::moneda_defecto( $op );
$uid      = 'b-' . $variante;
?>
<form class="buscador buscador--<?php echo esc_attr( $variante ); ?>" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'inmo_propiedad' ) ); ?>" role="search" aria-label="Buscar propiedades">
	<fieldset class="buscador__op">
		<legend class="sr">Operación</legend>
		<?php if ( 'resultados' === $variante ) : ?>
			<label class="chip"><input type="radio" name="operacion" value="" <?php checked( '', $f['operacion'] ); ?>><span>Todas</span></label>
		<?php endif; ?>
		<label class="chip"><input type="radio" name="operacion" value="venta" <?php checked( 'venta', $f['operacion'] ? $f['operacion'] : ( 'portada' === $variante ? 'venta' : '' ) ); ?> data-moneda="UF"><span>Comprar</span></label>
		<label class="chip"><input type="radio" name="operacion" value="arriendo" <?php checked( 'arriendo', $f['operacion'] ); ?> data-moneda="CLP"><span>Arrendar</span></label>
	</fieldset>
	<div class="buscador__campos">
		<div class="campo">
			<label for="<?php echo esc_attr( $uid ); ?>-comuna">Comuna</label>
			<select id="<?php echo esc_attr( $uid ); ?>-comuna" name="comuna">
				<option value="">Todas las comunas</option>
				<?php foreach ( Inmo_Busqueda::comunas( true ) as $region => $comunas ) : ?>
					<optgroup label="<?php echo esc_attr( $region ); ?>">
						<?php foreach ( $comunas as $slug => $nombre ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f['comuna'], $slug ); ?>><?php echo esc_html( $nombre ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="campo">
			<label for="<?php echo esc_attr( $uid ); ?>-tipo">Tipo</label>
			<select id="<?php echo esc_attr( $uid ); ?>-tipo" name="tipo">
				<option value="">Todos los tipos</option>
				<?php foreach ( Inmo_Esquema::TIPOS as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['tipo'], $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<fieldset class="campo campo--precio">
			<legend>Precio</legend>
			<div class="precio-fila">
				<label class="sr" for="<?php echo esc_attr( $uid ); ?>-min">Precio mínimo</label>
				<input id="<?php echo esc_attr( $uid ); ?>-min" type="text" inputmode="decimal" name="precio_min" placeholder="Desde" value="<?php echo esc_attr( '' !== $f['precio_min'] ? $f['precio_min'] + 0 : '' ); ?>">
				<label class="sr" for="<?php echo esc_attr( $uid ); ?>-max">Precio máximo</label>
				<input id="<?php echo esc_attr( $uid ); ?>-max" type="text" inputmode="decimal" name="precio_max" placeholder="Hasta" value="<?php echo esc_attr( '' !== $f['precio_max'] ? $f['precio_max'] + 0 : '' ); ?>">
				<label class="sr" for="<?php echo esc_attr( $uid ); ?>-moneda">Moneda</label>
				<select id="<?php echo esc_attr( $uid ); ?>-moneda" name="moneda" data-moneda-select>
					<option value="UF" <?php selected( $moneda, 'UF' ); ?>>UF</option>
					<option value="CLP" <?php selected( $moneda, 'CLP' ); ?>>$</option>
				</select>
			</div>
		</fieldset>
		<?php if ( 'resultados' === $variante ) : ?>
			<div class="campo">
				<label for="<?php echo esc_attr( $uid ); ?>-dorm">Dormitorios</label>
				<select id="<?php echo esc_attr( $uid ); ?>-dorm" name="dormitorios">
					<option value="">Cualquiera</option>
					<?php for ( $i = 1; $i <= 4; $i++ ) : ?>
						<option value="<?php echo (int) $i; ?>" <?php selected( (int) $f['dormitorios'], $i ); ?>><?php echo (int) $i; ?> o más</option>
					<?php endfor; ?>
				</select>
			</div>
			<div class="campo">
				<label for="<?php echo esc_attr( $uid ); ?>-banos">Baños</label>
				<select id="<?php echo esc_attr( $uid ); ?>-banos" name="banos">
					<option value="">Cualquiera</option>
					<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
						<option value="<?php echo (int) $i; ?>" <?php selected( (int) $f['banos'], $i ); ?>><?php echo (int) $i; ?> o más</option>
					<?php endfor; ?>
				</select>
			</div>
			<input type="hidden" name="orden" value="<?php echo esc_attr( $f['orden'] ); ?>">
		<?php endif; ?>
		<button class="boton boton--primario buscador__enviar" type="submit">Buscar</button>
	</div>
</form>
