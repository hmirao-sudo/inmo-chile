# Entrega 2 — Oportunidades, visitas, ofertas, plantillas, PDF y expediente

Se instala sobre la Entrega 1: reemplaza la carpeta del plugin `inmo-chile` (o sube el nuevo .zip) y la base de datos se actualiza sola al entrar al admin (versión de datos 2).

## Qué se agregó

| Pantalla | Qué hace |
|---|---|
| **Inmobiliaria → Oportunidades** | Tablero por etapas: Primer contacto → Calificado → Visita → Oferta / negociación → Reserva → Contrato / promesa → Cierre ganado / perdido. Cada tarjeta muestra cliente, propiedad, valor, próxima acción y corredor, y se mueve con un selector (sin arrastrar, accesible con teclado). Filtros por operación y corredor. Las cerradas se muestran durante 90 días. |
| **Ficha de la oportunidad** | Resumen; próxima acción y fecha; **condiciones de la operación** (renta o precio acordado, inicio, plazo, día de pago, garantía, aval, pie, crédito, fechas de promesa y escritura, notaría); visitas y consultas con confirmar o reprogramar; **ofertas** con estados (presentada, contraoferta, aceptada, rechazada, retirada); **reserva**, que deja la propiedad Reservada; **cierre ganado**, que deja la propiedad Vendida o Arrendada y cierra las demás oportunidades con motivo; **cierre perdido** con motivo obligatorio; documentos; notas e historial. |
| **Inmobiliaria → Plantillas de documentos** | Mandato, orden de visita, reserva, arriendo y promesa. Son editables, con versión automática y todas las revisiones guardadas. Muestran la lista de variables disponibles. |
| **Preparar documento** | Elige la plantilla, muestra una **vista previa** con los datos reales y marca en rojo lo que falta. El botón Emitir queda bloqueado (y el servidor lo valida) hasta completar todo. Emitir asigna un número (`ARR-2026-0001`), congela el texto y genera el PDF. |
| **Detalle del documento** | Estado de emisión (emitido o anulado) y de firma (pendiente, parcial, firmado, rechazado) por firmante, con copia firmada adjunta. Incluye la **verificación de integridad** del PDF (huella SHA-256) y la anulación con motivo, que conserva el archivo. |
| **Ficha interna → Expediente** | Oportunidades, ofertas y documentos emitidos de la propiedad. Lista de **antecedentes** para la compraventa (dominio vigente; hipotecas y gravámenes; títulos de 10 años; avalúo fiscal; contribuciones; no expropiación; recepción final; gastos comunes; cédulas) o para el arriendo, con carga de cada uno. **Archivo de la escritura** definitiva. |
| **Ficha del contacto** | Sus oportunidades y el botón «Nueva oportunidad» (con o sin código de propiedad). |

Las consultas y visitas que llegan desde la web **crean o reutilizan la oportunidad** del contacto para esa propiedad. Una visita confirmada mueve la oportunidad a «Visita», una oferta a «Oferta / negociación» y la emisión de un arriendo o promesa a «Contrato / promesa».

## Reglas de los documentos

- En la plantilla, toda variable `{{…}}` es **obligatoria**; con `?` es opcional (`{{aval.nombre?}}`).
- La **emisión, la firma y el cierre son independientes**: un contrato puede estar firmado sin que la operación esté cerrada, y viceversa.
- Cada documento emitido guarda el texto completo, los datos usados, la versión y la revisión de la plantilla, y la huella del PDF. **Editar la plantilla después no lo altera.**
- Los montos se muestran en su moneda original y en palabras («quinientos cincuenta mil pesos», «cinco mil cincuenta unidades de fomento»).
- Los PDF se guardan en el almacenamiento privado: solo los abren administración y el corredor responsable.
- **Las plantillas incluidas son de MUESTRA, sin validez legal.** Al editarlas y guardar con el texto de tu abogado, el aviso de muestra desaparece. No hace falta tocar código.

## Tecnología

- PDF con **Dompdf 3.1** (LGPL, incluido en `inmo-chile/vendor/`, sin costo recurrente). Se puede reemplazar con el filtro `inmo_generar_pdf` (por ejemplo, para usar un servicio externo).
- Tablas nuevas: `inmo_oportunidades`, `inmo_ofertas` e `inmo_emisiones`. Columnas nuevas: `oportunidad_id` en consultas e historial y `subtipo` en documentos.
- Plantillas: tipo de contenido privado `inmo_plantilla` con revisiones ilimitadas. Solo administración las edita.

## Pruebas ejecutadas (WordPress 7.1.5 es_CL, PHP 8.3, MariaDB 10.11)

`pruebas/ejecutar.sh` reinicia los datos y corre ambas suites: **Entrega 1: 33/33** y **Entrega 2: 23/23**.

- **Prueba 3:**
  - desde la oportunidad de arriendo (CP-0003), la vista previa trae la renta de $550.000, la garantía de $550.000 y las partes y la dirección correctas;
  - sin el RUT de las partes el botón queda bloqueado y el servidor rechaza un envío forzado;
  - con los RUT completos se emite ARR-2026-0001, y el PDF (verificado con `pdftotext`) contiene renta, garantía, partes y plazo;
  - al editar la plantilla sube la versión, y el PDF y el texto emitidos quedan idénticos (misma huella);
  - las firmas pasan a parcial y luego a firmado sin cerrar la operación.
- **Prueba 4, parte de esta entrega:**
  - la casa CP-0001 baja de UF 5.200 a UF 5.100 y el sitio actualiza el mismo aviso (misma URL y código); el historial registra el cambio;
  - oferta de UF 5.050 aceptada y reserva: la propiedad queda «Reservada» en el sitio;
  - cierre ganado: la propiedad queda Vendida y sale del sitio (404), y la otra oportunidad se cierra como perdida con motivo;
  - un corredor ajeno recibe 403 en la oportunidad y en el contrato.
- **Pendiente de la prueba 4 (Entrega 3):** actualización y retiro en Yapo y Portal Inmobiliario, estados de retiro por canal y reintento sin duplicar.

## Pendiente

- **Tus modelos chilenos revisados** por el abogado: se cargan editando cada plantilla.
- **Entrega 3:**
  - conectores por canal (Yapo, Portal Inmobiliario mediante la API de Mercado Libre) con un simulador local;
  - retiro solicitado y confirmado, reintentos y reporte de errores;
  - firma electrónica con proveedor configurable, que reemplaza el seguimiento manual de firmas.
- **Mejoras posibles:** exportación CSV del CRM; recordatorios por correo de la próxima acción; plantillas por propietario o con cláusulas opcionales.
