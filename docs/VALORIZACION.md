# Valorización automática (plugin 0.5.0)

Para actualizar, sube el plugin. La base de datos pasa a la versión 5 (tablas `inmo_comparables` e `inmo_valorizaciones`).

## Qué hace

| Dónde | Qué ve cada uno |
|---|---|
| **Web → «Solo saber cuánto vale»** | El propietario que indica tipo, comuna y superficie ve al instante un **rango referencial** de venta y de arriendo, con la cantidad de comparables, la comuna y la confianza. Se le aclara que no es una tasación. Solo se muestra con confianza media o alta. Sin superficie o sin datos suficientes, no se inventa: un corredor lo contacta. |
| **Bandeja «Consultas y visitas»** | Cada solicitud de valorización trae el rango calculado (o «sin datos suficientes») y los datos ingresados. |
| **Ficha interna → caja «Valorización automática»** | Rango, valor central, valor por m², confianza, dispersión, y si el precio publicado está **dentro, sobre o bajo** el rango (con el % de diferencia). |
| **Inmobiliaria → Valorización** | **Calculadora** y detalle de cada comparable usado (origen, fecha, m², precio, valor por m², peso). **Comparables de mercado:** carga manual o **importación CSV**, que informa las filas inválidas con su número. |

## Método (explicable)

1. **Comparables:**
   - del catálogo propio: precio publicado de lo disponible o reservado, y precio acordado de lo vendido o arrendado;
   - los comparables de mercado que cargue la corredora.
2. **Filtros:**
   - mismo tipo y operación;
   - superficie entre 60% y 140%;
   - antigüedad máxima de 24 meses (configurable);
   - misma comuna o, si faltan, la región, con menor peso.
3. **Valor por m²:** en UF para venta y en pesos para arriendo, con la UF de la fecha de cada dato. A los precios publicados se les descuenta un margen de negociación (3%, configurable).
4. **Pesos:** similitud de superficie y dormitorios × antigüedad × tipo de dato (cierre 1,0; publicación 0,8).
5. **Resultado:**
   - mediana ponderada como valor central y percentiles 25–75 como rango;
   - ajuste opcional por estacionamientos;
   - redondeo a la decena de UF o a $5.000.
6. **Confianza:**
   - alta: 8 o más comparables en la comuna y dispersión ≤ 15%;
   - media: 4 o más y dispersión ≤ 30%;
   - baja en los demás casos. Con menos de 3 comparables no se estima.

Cada estimación queda registrada con los datos de entrada y los comparables usados, para su trazabilidad.

**Ajustes** (Inmobiliaria → Ajustes → Valorización automática): mostrar o no el rango al público, mínimo de comparables, antigüedad máxima, descuento de negociación y valor por estacionamiento (venta en UF, arriendo en $/mes).

## Formato CSV de comparables

```
fecha;tipo;operacion;comuna;superficie;dormitorios;banos;estacionamientos;precio;moneda;es_cierre;fuente;url
2026-10-20;departamento;arriendo;Ñuñoa;64;2;2;1;640000;CLP;si;Observado en portal;https://…
```

- **Separador:** punto y coma o coma.
- **Comuna:** nombre o slug.
- **Moneda:** UF o CLP.
- **Cierre:** `es_cierre` = si para un cierre real; no para un precio publicado.
- **Ejemplo ficticio:** `pruebas/datos/comparables-prueba.csv`.

## Pruebas (WordPress 7.1.5 es_CL)

`pruebas/ejecutar.sh` ejecuta las cinco suites. Esta suite verifica:

- importa 13 comparables y rechaza 2 filas con su número;
- un depto de 60 m² en Ñuñoa da un rango de **$595.000 – $605.000** (≈ $10.000/m²), sin inventar un valor de venta;
- el aviso de que no es tasación;
- el registro de la estimación y su aparición en la bandeja;
- sin superficie, o con un local en Tiltil, no se muestra rango;
- una casa de 170 m² en La Reina da **UF 4.990 – 5.100** y usa el cierre propio CP-0001;
- se excluye un dato de 2023;
- la ficha de Providencia usa la región como respaldo y compara con el precio publicado;
- con el rango público desactivado, el propietario no lo ve y el corredor sí.

## Límites y siguiente paso

- **La calidad depende de los datos:** mientras el catálogo sea pequeño, conviene cargar comparables de mercado de cada comuna en la que trabajen.
- **Lo que el modelo no considera:** estado, terminaciones, vista, piso, orientación ni la antigüedad del edificio. Por eso el resultado es referencial y el corredor lo ajusta en la visita.
- **Evolución posible:**
  - importar comparables desde los portales cuando se retome esa integración;
  - ajustes por piso y antigüedad;
  - informe de valorización en PDF para el propietario.
