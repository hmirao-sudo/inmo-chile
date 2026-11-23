# Portal del propietario y UF automática (plugin 0.4.0, tema 0.2.0)

Para actualizar, sube el plugin y el tema. Al entrar al admin se crea la página **Portal de propietarios** (`/portal-propietario/`) y el rol «Propietario/a (portal)».

## Portal del propietario

**Cómo se da acceso**

1. Abre la ficha del contacto (solo aparece si es titular de alguna propiedad) y pulsa **«Dar acceso al portal»**.
2. Se crea su cuenta y se le envía un enlace personal para crear su contraseña. Si el correo no sale, el enlace se muestra una vez para copiarlo y enviarlo por WhatsApp.
3. Luego entra siempre desde **Portal de propietarios (acceso)**, en el pie del sitio. Si olvida la contraseña, la recupera ahí mismo.
4. **«Revocar acceso»** cierra sus sesiones y bloquea el ingreso. «Reenviar enlace» lo reactiva.

**Qué ve cada propietario (solo lo suyo)**

| Sección | Contenido |
|---|---|
| Mis propiedades | Foto, código, estado comercial, si está publicada, y número de consultas, visitas y ofertas |
| Detalle | Precio publicado, enlace al aviso y corredor responsable |
| Consultas y visitas | Cantidades y citas con su estado, **sin nombres, correos ni teléfonos** de los interesados |
| Ofertas | Fecha, monto y estado, sin la identidad del ofertante |
| Arriendo | Arrendatario, renta, día de pago, vigencia y estado de cada mes (pagado, pendiente, vencido) |
| Liquidaciones | Recaudado, descuentos, monto a transferir, estado de transferencia y descarga en PDF |
| Documentos | Mandato, contratos de arriendo o promesa (emitidos y firmados), sus liquidaciones, respaldos de gastos, antecedentes y escritura |
| Escribir a mi corredor | El mensaje llega a la bandeja «Consultas y visitas» con el código de la propiedad |

**Seguridad**

- El propietario no entra al escritorio de WordPress (se le redirige al portal) ni ve la barra de administración.
- Cada descarga verifica que el documento sea de una propiedad suya y de un tipo permitido. Nunca ve órdenes de visita ni reservas de terceros, fotos internas ni comprobantes del arrendatario.
- La página no se indexa en buscadores ni se guarda en caché.
- Los datos bancarios no se editan desde el portal: el cambio se pide por mensaje y la corredora lo confirma por teléfono, para prevenir fraudes.

## UF automática desde la CMF

Se configura en **Inmobiliaria → Ajustes → Valor UF**: elige «API CMF Chile» y pega tu **API key**, que se solicita gratis en https://api.cmfchile.cl.

- **Recurso usado:** «UF de fechas posteriores a un mes de un año» de la [API CMF Bancos v3](https://api.cmfchile.cl/documentacion/UF.html), desde el mes anterior y en formato JSON.
- **Valores por adelantado:** la UF se publica hasta el día 9 del mes siguiente, así que la respuesta trae también esos días.
- **Cuándo se actualiza:** con la tarea diaria y con el botón **«Actualizar la UF ahora»**.
- **Valor vigente:** se guarda la serie completa. El vigente es el **de hoy**, no el del último día publicado; la fecha y la fuente se muestran en el sitio.
- **Rentas en UF:** cada cobro usa la UF **exacta del día de vencimiento** cuando ya está publicada; si aún no lo está, usa la última conocida y lo deja anotado en el cobro.
- **Errores:** si la CMF responde con error (por ejemplo «API key no valida»), se informa el mensaje, queda en el historial y la UF vigente no cambia.
- **mindicador.cl:** queda como alternativa sin clave. La carga manual sigue disponible.

## Pruebas (WordPress 7.1.5 es_CL)

`pruebas/ejecutar.sh` ejecuta las cuatro suites: **Entrega 1: 33/33, Entrega 2: 23/23, Arriendos: 21/21, Portal y UF: 19/19.**

**Portal:**

- la corredora da acceso; la propietaria crea su contraseña desde el celular e ingresa;
- no entra a wp-admin y no ve propiedades de otros dueños;
- ve el arriendo ($561.550), el mes pagado y la liquidación LIQ-2026-0001 de $464.185 transferida, y descarga el PDF;
- las visitas aparecen sin datos de los interesados;
- se rechazan los documentos ajenos;
- envía un mensaje que llega al CRM;
- al revocar el acceso no puede volver a entrar;
- otra propietaria con dos propiedades ve ambas y sus ofertas.

**UF:**

- Se simularon las respuestas con el **formato oficial** de la CMF (`{"UFs":[{"Valor":"39.384,25","Fecha":"2026-11-23"}…]}` y `{"CodigoHTTP":421,"CodigoError":91,"Mensaje":"API key no valida"}`). Con la clave de ejemplo de la documentación, la API real responde exactamente ese error, que se interpreta bien.
- Se comprobó:
  - la URL consultada;
  - que el vigente es el valor de hoy;
  - que la serie llega hasta el día 9 del mes siguiente;
  - que se usa la UF exacta para un vencimiento futuro;
  - que con una clave inválida la UF no cambia.
- **Pendiente:** probar con tu API key real. Basta pegarla en Ajustes y pulsar «Actualizar la UF ahora».
