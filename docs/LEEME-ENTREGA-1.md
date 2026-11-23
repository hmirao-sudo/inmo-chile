# Entrega 1 — Sitio y gestión inmobiliaria en WordPress (Chile)

Captación, ficha interna, catálogo, buscador, ficha pública y recepción de consultas en el CRM.
Incluye 2 inmuebles ficticios, 1 propietaria ficticia y 2 corredores ficticios.

| Archivo | Qué es |
|---|---|
| `inmo-chile.zip` | Plugin: entidades, permisos, reglas de negocio, formularios y CRM |
| `inmo-vitrina.zip` | Tema: presentación pública (portada, resultados, ficha, páginas) |
| `pruebas/` | Pruebas de aceptación automatizadas (Playwright) y utilidades |
| `capturas/` | Capturas de escritorio (`d-*`) y celular (`m-*`) tomadas durante las pruebas |

---

## 1. Instalación (5 minutos)

Requisitos: WordPress 6.2 o superior (probado en 6.5.5), PHP 7.4 o superior (probado en 8.3), MySQL o MariaDB, extensión GD.

1. **Plugins → Añadir nuevo → Subir plugin** → `inmo-chile.zip` → Activar.
   Al activarse crea las tablas, el rol «Corredor/a de propiedades», las regiones y comunas (RM completa, más V y VIII) y las páginas *Vende o arrienda tu propiedad*, *Completa la ficha de tu propiedad* y *Contacto*.
2. **Apariencia → Temas → Añadir nuevo → Subir tema** → `inmo-vitrina.zip` → Activar.
3. **Ajustes → Enlaces permanentes** → «Nombre de la entrada» → Guardar.
4. **Inmobiliaria → Ajustes** → revisa la marca, el contacto y el **valor UF** → *Cargar datos de prueba*.
5. Correo: instala un plugin SMTP (p. ej. WP Mail SMTP) para que salgan las confirmaciones. Sin SMTP todo funciona igual y el historial muestra «Correo NO enviado».

Usuarios de prueba que se crean: `camila.rojas` y `diego.munoz` (rol corredor), con contraseña `Prueba.Corredor.2026`. **Elimínalos en producción** (Ajustes → *Eliminar datos de prueba*).

**Recomendado en producción:** en `wp-config.php`, `define( 'INMO_DIR_PRIVADO', '/ruta/fuera/del/webroot/inmo-privado' );`.
Si dejas el valor por defecto (`uploads/inmo-privado`), Apache e IIS lo bloquean con `.htaccess` / `web.config`. En **nginx** agrega:

```
location ^~ /wp-content/uploads/inmo-privado/ { deny all; }
```

---

## 2. Mapa de pantallas

**Sitio público (tema)**

| Pantalla | URL | Contenido |
|---|---|---|
| Inicio | `/` | Buscador primero (Comprar / Arrendar, comuna, tipo, precio en UF o $); propiedades seleccionadas (administrables con «Destacar en la portada»); tres recorridos: buscar, visitar y entregar en corretaje; solicitud de valorización |
| Resultados | `/propiedades/?operacion=arriendo&comuna=nunoa&tipo=…&precio_min=…&precio_max=…&moneda=CLP&dormitorios=…&orden=…` | Tarjetas (foto, precio y moneda, operación, comuna, superficie y atributos según el tipo, código); filtros en un panel desplegable en celular; ordenar; «Compartir búsqueda»; caso sin resultados con atajos para quitar filtros y aviso por consulta general |
| Ficha pública | `/propiedades/{titulo}-{codigo}/` | Código, galería accesible, precio original más equivalencia UF/CLP (con fuente y fecha), gastos comunes, características según tipo y operación, descripción, equipamiento, ubicación con precisión configurable; pestañas «Consultar por esta propiedad» y «Solicitar visita»; barra fija en celular |
| Propietarios | `/publica-tu-propiedad/` (`?quiero=valorizar`) | Formulario corto en 3 pasos: qué quiere hacer (vender, arrendar o solo valorizar), propiedad, contacto |
| Invitación | `/completar-propiedad/?t=…` | Enlace individual con vencimiento: características según el tipo, equipamiento, descripción y hasta 15 fotos. No se indexa |
| Contacto | `/contacto/` | Consulta general sin propiedad |
| Ir por código | `/?codigo=CP-0002` | Redirige a la ficha |

**Gestión interna (menú «Inmobiliaria»)**

| Pantalla | Contenido |
|---|---|
| Panel | Captaciones en revisión, visitas por confirmar, consultas nuevas y captaciones por vencer en 30 días |
| Propiedades | Listado con foto, código, operación/tipo, comuna, precio, estado, corredor y consultas pendientes; filtro por estado; búsqueda por código |
| Ficha interna | **Estado y publicación** (estado comercial, visibilidad, corredor, destacado, «Aprobar y publicar» o «Enviar a revisión»). **Resumen**: dueño, interesados, visitas, ofertas (Entrega 2), documentos y canales. Operación, precio y ubicación (privada aparte). Características según el tipo. Fotos autorizadas (ordenables) y fotos del propietario pendientes. **Captación** (privado): titulares, mandato, exclusividad, comisión, vencimiento, precio mínimo, notas, documentos e invitación. **Consultas, visitas e historial** con confirmar o reprogramar |
| Contactos (CRM) | Listado con búsqueda por nombre, correo, teléfono o RUT y filtro por rol. La ficha del contacto muestra datos, roles, corredor, preferencias, propiedades como titular, consultas agrupadas por inmueble y consultas generales, registro manual de consulta o nota, e historial |
| Consultas y visitas | Bandeja por tipo y estado con acciones en línea |
| Regiones y comunas | Edición de la taxonomía (solo administración) |
| Ajustes | Marca, colores, logo, contacto, UF (valor, fecha y fuente), prefijo de código, precisión por defecto, días de invitación, corredor por defecto, datos de prueba |

---

## 3. Modelo de datos

```
                          ┌───────────────────────────────┐
  taxonomías ────────────►│ inmo_propiedad (wp_posts, CPT)│◄──── wp_postmeta _inmo_* (datos PÚBLICOS)
  inmo_operacion (venta/  │ título, descripción, autor =  │      código, precio, moneda, precio_clp (ref.),
   arriendo)              │ corredor responsable,         │      estado, visibilidad, superficies, dorm.,
  inmo_tipo (5 tipos)     │ post_status derivado          │      baños, gastos comunes, garantía, reajuste…,
  inmo_ubicacion          └──────────────┬────────────────┘      equipamiento, sector, precisión, lat/lng
   (región > comuna)                     │ 1:1                   públicas, galería (IDs autorizados), destacado
                                         ▼
                      inmo_propiedad_privado  (dirección, unidad, rol SII, lat/lng exactas, mandato,
                                               exclusividad, comisión, vencimiento captación,
                                               precio mínimo, notas)
  inmo_contactos ◄──N:M── inmo_propiedad_contacto (rol = titular)
   (nombre, email, tel,                 ▲
    RUT, roles, origen,                 │
    corredor, preferencias,   inmo_consultas (tipo: consulta | visita | captación | valorización | general;
    consentimiento)              contacto_id, propiedad_id o 0, código, mensaje, datos JSON, estado,
                                 visita_fecha, corredor, origen, consentimiento)
  inmo_documentos   (propiedad, contacto, tipo, archivo privado aleatorio, sha256, estado)
  inmo_invitaciones (propiedad, contacto, hash del token, vence, usada, anulada)
  inmo_historial    (propiedad / contacto / consulta, usuario, acción, detalle, fecha)
```

**Qué va en cada lugar y por qué**

- **Tipo de contenido + postmeta**: la ficha pública. Así se aprovechan la URL, las revisiones de texto, la biblioteca de medios, el sitemap y el editor. El catálogo queda portable con el exportador WXR de WordPress.
- **Taxonomías** para operación, tipo y región > comuna. Son filtros rápidos e indexados y la base de las URLs compartibles. Las comunas se agregan desde el admin.
- **Tablas propias** para lo privado y lo relacional:
  - Los datos privados de la propiedad **no** van en postmeta, para que no se filtren por REST, feeds, exportadores genéricos ni plugins que listan metadatos.
  - Contactos, la relación N:M de titulares, consultas, documentos, invitaciones e historial requieren claves, índices y consultas por persona y por inmueble que las entradas de WordPress no modelan bien.
  - El CRM es portable: son tablas planas con claves simples. Está previsto un exportador CSV en la Entrega 2. Los exportadores de privacidad de WordPress ya funcionan.
- **Precio original y moneda** se guardan tal cual. `precio_clp` es solo una referencia para filtrar y ordenar, y se recalcula al cambiar la UF.
- **Estado comercial ≠ visibilidad ≠ estado de publicación.** El `post_status` siempre se deriva:
  - borrador → `draft`
  - en revisión → `pending`
  - disponible o reservada con visibilidad pública → `publish`
  - disponible o reservada oculta → `private`
  - vendida, arrendada o archivada → `private`
- **Código estable** (`CP-0001`): se asigna con una secuencia atómica en MySQL y nunca cambia. Forma parte de la URL.

---

## 4. Qué ve cada rol

| | Visitante | Corredor/a (`inmo_corredor`) | Administración |
|---|---|---|---|
| Catálogo público, precio y fotos autorizadas | ✔ | ✔ | ✔ |
| Crear captaciones y editar **sus** propiedades | — | ✔ (solo las propias) | ✔ todas |
| Publicar (aprobar) una captación | — | ✖ queda «en revisión» | ✔ |
| Titulares, RUT, dirección exacta, mandato, comisión, notas y documentos | ✖ | ✔ solo de sus propiedades | ✔ |
| Contactos y consultas | ✖ | Los asignados a él/ella o vinculados a sus propiedades | ✔ todos |
| Ajustes, comunas y datos de prueba | ✖ | ✖ | ✔ |

**Protecciones**

- El tipo de contenido no está en la API REST.
- La vista pública usa un único modelo saneado (`inmo_propiedad()`).
- Los feeds solo muestran código, precio y comuna, y el autor aparece como la corredora.
- No hay sitemap de usuarios ni archivos de autor.
- Las páginas de adjunto están desactivadas.
- Los documentos y las fotos del propietario se guardan fuera de la biblioteca, con nombre aleatorio y descarga mediante un endpoint con nonce y permiso por propiedad. Cada acceso denegado queda en la bitácora.
- La página de invitación lleva `noindex` y `no-referrer`, y el token se guarda solo como hash HMAC.
- Formularios: trampa para bots (campo oculto más tiempo mínimo firmado) y límite de 8 envíos por IP cada 10 minutos. La IP se guarda solo como hash.
- Consentimiento con fecha, exportación y anonimización por solicitud del titular (Herramientas → Privacidad), alineado con la Ley 21.719.

---

## 5. Decisiones técnicas y plugins evaluados

La Entrega 1 **no depende de ningún plugin de terceros**. Evaluación para las etapas siguientes:

| Necesidad | Opción | Uso | Costo recurrente | Cómo se reemplaza |
|---|---|---|---|---|
| Envío de correo | WP Mail SMTP (gratis) o SMTP del hosting | Entregar confirmaciones | Gratis; Pro desde ~US$49/año | Cualquier plugin que implemente `wp_mail` |
| PDF (Entrega 2) | Dompdf (librería PHP, LGPL) dentro del plugin | Generar contratos desde plantillas versionadas | Ninguno | Detrás de una interfaz `Generador_PDF` |
| Firma electrónica (Entrega 3) | Proveedor configurable (p. ej. firma simple o avanzada en Chile, DocuSign) | Envío, seguimiento por firmante y archivo | Según proveedor (por documento o plan) | Conector por proveedor detrás de una interfaz `Proveedor_Firma` |
| Formularios | Gravity Forms o WPForms | **No se usan**: los formularios propios crean contacto, consulta y captación en el CRM sin duplicar datos | Desde ~US$59/año | — |
| Anti-spam reforzado | Cloudflare Turnstile (gratis) | Si aparece spam real | Gratis | Hook en `Inmo_Formularios::anti_spam()` |
| UF automática | API del Banco Central o mindicador.cl | Actualizar la UF a diario | Gratis | Hoy es manual (valor, fecha y fuente visibles) |
| Mapas | OpenStreetMap embebido (sin clave) | Mostrar el sector | Gratis | Google Maps o Mapbox con clave |

---

## 6. Suposiciones reversibles

- **Marca provisional**: «Cordillera Propiedades», colores #1f4e5f / #d9822b, isotipo de texto y datos de contacto ficticios. Todo se cambia en **Inmobiliaria → Ajustes**, sin tocar código.
- **Textos** originales. Las imágenes de los inmuebles de prueba son **ilustraciones generadas localmente** (sin derechos de terceros) y llevan el rótulo «Imagen ilustrativa».
- **Moneda sugerida**: venta en UF y arriendo en CLP (editable por propiedad). El valor UF de ejemplo es **$39.500 al 23-11-2026 y es referencial: verifícalo en sii.cl** y actualízalo.
- **Prefijo de código** `CP` (configurable; no altera códigos existentes).
- Cada ficha tiene **una operación**. Si un inmueble se ofrece en venta y arriendo a la vez, se crean dos fichas. Se puede ampliar.
- **Precisión de ubicación por defecto**: «aproximada» (desplazamiento determinístico de ~150–400 m).
- **Aprobación**: solo administración publica captaciones nuevas. Un corredor puede editar el precio de una propiedad ya aprobada sin nueva revisión.
- **Invitación**: vence en 7 días y es de un solo uso. Las fotos quedan privadas hasta que el corredor las autoriza.
- **Comunas iniciales**: RM completa más las principales de Valparaíso y Biobío.

---

## 7. Ejecutado y pendiente

**Ejecutado y verificado en esta sesión** (WordPress 6.5.5 + MariaDB 10.11 + PHP 8.3, servidor local):

- Instalación y activación del plugin y el tema, y carga de datos de prueba.
- Pruebas de aceptación **1 y 2** automatizadas con navegador real (vista de celular Pixel 7 y escritorio): **33/33 verificaciones pasan** (`pruebas/aceptacion.js`, resultado en `pruebas/resultado-aceptacion.json`). Cubren:
  - entrega de la propietaria, captación en revisión e invitación de un solo uso;
  - fotos privadas hasta autorizarse y aprobación;
  - filtro de arriendos en Ñuñoa, precio y fotos autorizadas visibles, sin datos privados en el HTML;
  - «Volver a los resultados» con filtros;
  - mandato: la corredora responsable lo descarga, otro corredor recibe 403 y el visitante es rechazado;
  - un contacto con consultas por dos inmuebles e historial por inmueble;
  - visita desde el celular que queda pendiente y se confirma y reprograma desde la ficha;
  - corredor ajeno sin acceso al contacto;
  - caso sin resultados.
- Revisión de fugas: fichas, resultados, feed, REST y sitemap sin dirección, RUT, titular, coordenadas exactas ni mandato.
- Registro de PHP sin errores ni advertencias del plugin y el tema durante las pruebas.

**No verificado aquí**

- Envío real de correos: no hay SMTP en el entorno; queda registrado como «no enviado».
- Mapa de OpenStreetMap: el entorno no tiene salida a internet; se ve en blanco en las capturas.
- Traducción del núcleo de WordPress al español de Chile: wordpress.org está bloqueado aquí. Los textos del plugin y el tema ya están en español; en tu instalación con `es_CL` el admin completo quedará en español.
- Pruebas en WordPress 6.6–7.x: el código usa solo API estables, pero conviene correr `pruebas/` en tu versión.

**Pendiente (próximas entregas)**

- **Entrega 2**:
  - oportunidades con etapas (kanban) hasta ganado o perdido;
  - ofertas, reserva y cierre;
  - plantillas versionadas (mandato, orden de visita, reserva, arriendo, promesa) con vista previa, validación de faltantes y PDF inmutable por versión;
  - expediente por propiedad;
  - exportación CSV del CRM.
  - Las pruebas de aceptación 3 y 4 corresponden a esta etapa y la siguiente.
- **Entrega 3**:
  - conectores independientes Yapo y Portal Inmobiliario (API de Mercado Libre), más un simulador local identificable;
  - sincronización y retiro con «retiro solicitado» y «retiro confirmado», reintentos idempotentes y reporte de errores;
  - firma electrónica con proveedor configurable;
  - verificar con cada portal el formato de inventario, la habilitación de la cuenta, las condiciones del plan y si permite importar consultas.
- **Ampliaciones**: cobranza mensual, liquidaciones a propietarios, valorización automática, búsqueda en lenguaje natural y portal privado del dueño.

---

## 8. Reproducir las pruebas

Con el sitio en `http://localhost:8080`, datos de prueba y usuario `admin` / `Admin.Demo.2026` (ajusta la clave en `aceptacion.js`):

```bash
cd pruebas
npm i playwright   # si no está instalado
node aceptacion.js
```

El script `consulta.php` lee la base de datos para verificar el estado real; ajusta la ruta a `wp-load.php` si tu instalación está en otro lugar. `ejecutar.sh` reinicia los datos antes de cada corrida (solo para entornos de prueba).
