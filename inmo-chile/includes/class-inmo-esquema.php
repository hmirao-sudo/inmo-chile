<?php
/**
 * Esquema único de la ficha: tipos, operaciones, estados y campos según tipo y operación.
 * Admin, formulario del propietario, ficha pública y validación leen de aquí.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Esquema {

	const TIPOS = array(
		'departamento' => 'Departamento',
		'casa'         => 'Casa',
		'oficina'      => 'Oficina',
		'local'        => 'Local comercial',
		'terreno'      => 'Terreno',
	);

	const OPERACIONES = array(
		'venta'    => 'Venta',
		'arriendo' => 'Arriendo',
	);

	/** Estado comercial (independiente de la visibilidad). */
	const ESTADOS = array(
		'borrador'    => 'Borrador',
		'en_revision' => 'En revisión',
		'disponible'  => 'Disponible',
		'reservada'   => 'Reservada',
		'vendida'     => 'Vendida',
		'arrendada'   => 'Arrendada',
		'archivada'   => 'Archivada',
	);

	/** Estados que pueden mostrarse públicamente (si la visibilidad es pública). */
	const ESTADOS_PUBLICABLES = array( 'disponible', 'reservada' );

	/** Estados que requieren aprobación (capacidad inmo_aprobar) para entrar en ellos desde borrador/revisión. */
	const ESTADOS_APROBADOS = array( 'disponible', 'reservada' );

	const VISIBILIDAD = array(
		'publica' => 'Pública (sitio web)',
		'oculta'  => 'Oculta (solo uso interno)',
	);

	const PRECISION = array(
		'exacta'    => 'Ubicación exacta',
		'aproximada' => 'Aproximada (radio ~400 m)',
		'comuna'    => 'Solo comuna y sector',
	);

	const MONEDAS = array(
		'UF'  => 'UF',
		'CLP' => 'Pesos (CLP)',
	);

	const RESIDENCIAL = array( 'departamento', 'casa' );
	const CONSTRUIDO  = array( 'departamento', 'casa', 'oficina', 'local' );

	/**
	 * Campos públicos de la ficha (postmeta con prefijo _inmo_).
	 * tipos/operaciones: null = todos.
	 */
	public static function campos() {
		$c = array(
			'gastos_comunes'   => array( 'label' => 'Gastos comunes (CLP/mes)', 'tipo' => 'numero', 'grupo' => 'precio', 'tipos' => array( 'departamento', 'casa', 'oficina', 'local' ), 'formato' => 'clp' ),
			'contribuciones'   => array( 'label' => 'Contribuciones (CLP/trimestre)', 'tipo' => 'numero', 'grupo' => 'precio', 'operaciones' => array( 'venta' ), 'formato' => 'clp' ),
			'garantia_meses'   => array( 'label' => 'Garantía (meses de renta)', 'tipo' => 'numero', 'grupo' => 'arriendo', 'operaciones' => array( 'arriendo' ), 'step' => '0.5' ),
			'reajuste'         => array(
				'label'       => 'Reajuste',
				'tipo'        => 'select',
				'grupo'       => 'arriendo',
				'operaciones' => array( 'arriendo' ),
				'opciones'    => array(
					'ipc_semestral' => 'IPC semestral',
					'ipc_anual'     => 'IPC anual',
					'uf'            => 'Renta expresada en UF',
					'sin_reajuste'  => 'Sin reajuste',
				),
			),
			'vigencia_meses'   => array( 'label' => 'Plazo de contrato (meses)', 'tipo' => 'numero', 'grupo' => 'arriendo', 'operaciones' => array( 'arriendo' ) ),
			'disponible_desde' => array( 'label' => 'Disponible desde', 'tipo' => 'fecha', 'grupo' => 'arriendo', 'operaciones' => array( 'arriendo' ) ),
			'amoblado'         => array( 'label' => 'Amoblado', 'tipo' => 'select', 'grupo' => 'arriendo', 'operaciones' => array( 'arriendo' ), 'tipos' => array( 'departamento', 'casa', 'oficina' ), 'opciones' => array( 'no' => 'No', 'si' => 'Sí', 'semi' => 'Semi amoblado' ) ),
			'mascotas'         => array( 'label' => 'Acepta mascotas', 'tipo' => 'select', 'grupo' => 'arriendo', 'operaciones' => array( 'arriendo' ), 'tipos' => array( 'departamento', 'casa' ), 'opciones' => array( 'consultar' => 'Consultar', 'si' => 'Sí', 'no' => 'No' ) ),

			'sup_util'         => array( 'label' => 'Superficie útil', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => self::CONSTRUIDO, 'unidad' => 'm²', 'step' => '0.01', 'tarjeta' => true ),
			'sup_terraza'      => array( 'label' => 'Superficie terraza', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => array( 'departamento', 'oficina' ), 'unidad' => 'm²', 'step' => '0.01' ),
			'sup_construida'   => array( 'label' => 'Superficie construida', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => array( 'casa' ), 'unidad' => 'm²', 'step' => '0.01' ),
			'sup_terreno'      => array( 'label' => 'Superficie terreno', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => array( 'casa', 'terreno' ), 'unidad' => 'm²', 'step' => '0.01', 'tarjeta' => true ),
			'dormitorios'      => array( 'label' => 'Dormitorios', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => self::RESIDENCIAL, 'tarjeta' => true, 'corto' => 'dorm.' ),
			'banos'            => array( 'label' => 'Baños', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => self::CONSTRUIDO, 'tarjeta' => true, 'corto' => 'baños' ),
			'privados'         => array( 'label' => 'Privados', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => array( 'oficina' ), 'tarjeta' => true, 'corto' => 'privados' ),
			'estacionamientos' => array( 'label' => 'Estacionamientos', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => self::CONSTRUIDO, 'tarjeta' => true, 'corto' => 'estac.' ),
			'bodegas'          => array( 'label' => 'Bodegas', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => array( 'departamento', 'casa', 'oficina', 'local' ) ),
			'piso'             => array( 'label' => 'Piso', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => array( 'departamento', 'oficina' ) ),
			'orientacion'      => array(
				'label'    => 'Orientación',
				'tipo'     => 'select',
				'grupo'    => 'caracteristicas',
				'tipos'    => self::RESIDENCIAL,
				'opciones' => array( 'N' => 'Norte', 'S' => 'Sur', 'O' => 'Oriente', 'P' => 'Poniente', 'NO' => 'Nororiente', 'NP' => 'Norponiente', 'SO' => 'Suroriente', 'SP' => 'Surponiente' ),
			),
			'anio'             => array( 'label' => 'Año de construcción', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => self::CONSTRUIDO ),
			'frente_m'         => array( 'label' => 'Frente', 'tipo' => 'numero', 'grupo' => 'caracteristicas', 'tipos' => array( 'local', 'terreno' ), 'unidad' => 'm', 'step' => '0.01', 'tarjeta' => true ),
			'uso_suelo'        => array( 'label' => 'Uso de suelo', 'tipo' => 'texto', 'grupo' => 'caracteristicas', 'tipos' => array( 'terreno' ) ),
		);
		return apply_filters( 'inmo_campos_ficha', $c );
	}

	/** Equipamiento disponible por tipo. */
	public static function equipamiento( $tipo = null ) {
		$todos = array(
			'logia'          => array( 'Logia', array( 'departamento', 'casa' ) ),
			'calefaccion'    => array( 'Calefacción', self::CONSTRUIDO ),
			'aire'           => array( 'Aire acondicionado', self::CONSTRUIDO ),
			'cocina_amob'    => array( 'Cocina amoblada', self::RESIDENCIAL ),
			'termopanel'     => array( 'Ventanas termopanel', self::CONSTRUIDO ),
			'conserjeria'    => array( 'Conserjería 24 h', array( 'departamento', 'oficina' ) ),
			'ascensor'       => array( 'Ascensor', array( 'departamento', 'oficina' ) ),
			'piscina'        => array( 'Piscina', self::RESIDENCIAL ),
			'quincho'        => array( 'Quincho', self::RESIDENCIAL ),
			'gimnasio'       => array( 'Gimnasio', array( 'departamento' ) ),
			'jardin'         => array( 'Jardín', array( 'casa' ) ),
			'condominio'     => array( 'En condominio', array( 'casa' ) ),
			'sala_reuniones' => array( 'Sala de reuniones', array( 'oficina' ) ),
			'vitrina'        => array( 'Vitrina a la calle', array( 'local' ) ),
			'carga'          => array( 'Acceso de carga', array( 'local', 'terreno' ) ),
			'agua'           => array( 'Factibilidad de agua', array( 'terreno' ) ),
			'luz'            => array( 'Factibilidad eléctrica', array( 'terreno' ) ),
			'cerrado'        => array( 'Cierre perimetral', array( 'terreno' ) ),
		);
		$out = array();
		foreach ( $todos as $k => $d ) {
			if ( null === $tipo || in_array( $tipo, $d[1], true ) ) {
				$out[ $k ] = $d[0];
			}
		}
		return $out;
	}

	/** ¿Aplica el campo al tipo y operación dados? */
	public static function aplica( array $campo, $tipo, $operacion ) {
		if ( ! empty( $campo['tipos'] ) && $tipo && ! in_array( $tipo, $campo['tipos'], true ) ) {
			return false;
		}
		if ( ! empty( $campo['operaciones'] ) && $operacion && ! in_array( $operacion, $campo['operaciones'], true ) ) {
			return false;
		}
		return true;
	}

	public static function campos_para( $tipo, $operacion, $grupo = null ) {
		$out = array();
		foreach ( self::campos() as $k => $c ) {
			if ( ( null === $grupo || $c['grupo'] === $grupo ) && self::aplica( $c, $tipo, $operacion ) ) {
				$out[ $k ] = $c;
			}
		}
		return $out;
	}

	/** Etiqueta del precio según operación. */
	public static function etiqueta_precio( $operacion ) {
		return 'arriendo' === $operacion ? 'Renta mensual' : 'Precio solicitado';
	}

	/** Moneda sugerida por operación (supuesto reversible: venta en UF, arriendo en CLP). */
	public static function moneda_defecto( $operacion ) {
		return 'arriendo' === $operacion ? 'CLP' : 'UF';
	}

	/** Estados válidos según operación. */
	public static function estados_para( $operacion ) {
		$e = self::ESTADOS;
		if ( 'venta' === $operacion ) {
			unset( $e['arrendada'] );
		} elseif ( 'arriendo' === $operacion ) {
			unset( $e['vendida'] );
		}
		return $e;
	}

	/** Regiones de Chile y comunas iniciales (RM completa + principales de V y VIII; ampliable desde el admin). */
	public static function regiones_comunas() {
		return array(
			'Región Metropolitana de Santiago' => array( 'Alhué', 'Buin', 'Calera de Tango', 'Cerrillos', 'Cerro Navia', 'Colina', 'Conchalí', 'Curacaví', 'El Bosque', 'El Monte', 'Estación Central', 'Huechuraba', 'Independencia', 'Isla de Maipo', 'La Cisterna', 'La Florida', 'La Granja', 'La Pintana', 'La Reina', 'Lampa', 'Las Condes', 'Lo Barnechea', 'Lo Espejo', 'Lo Prado', 'Macul', 'Maipú', 'María Pinto', 'Melipilla', 'Ñuñoa', 'Padre Hurtado', 'Paine', 'Pedro Aguirre Cerda', 'Peñaflor', 'Peñalolén', 'Pirque', 'Providencia', 'Pudahuel', 'Puente Alto', 'Quilicura', 'Quinta Normal', 'Recoleta', 'Renca', 'San Bernardo', 'San Joaquín', 'San José de Maipo', 'San Miguel', 'San Pedro', 'San Ramón', 'Santiago', 'Talagante', 'Tiltil', 'Vitacura' ),
			'Región de Valparaíso'             => array( 'Concón', 'Quilpué', 'Valparaíso', 'Villa Alemana', 'Viña del Mar', 'Zapallar', 'Papudo', 'Algarrobo', 'El Quisco', 'San Antonio' ),
			'Región del Biobío'                => array( 'Concepción', 'Chiguayante', 'Hualpén', 'San Pedro de la Paz', 'Talcahuano', 'Los Ángeles' ),
		);
	}
}
