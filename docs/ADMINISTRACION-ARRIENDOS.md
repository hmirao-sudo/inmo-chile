# Módulo: administración de arriendos, cobranza y liquidaciones (plugin 0.3.0)

Se actualiza subiendo el plugin sobre la versión anterior. La base de datos pasa a la versión 3 al entrar al admin.

## Pantallas (menú Inmobiliaria)

| Pantalla | Qué hace |
|---|---|
| **Arriendos administrados** | Lista de arriendos vigentes y terminados. Se inicia desde la oportunidad de arriendo ganada (trae renta, partes, garantía y contrato emitido) o desde el expediente de la propiedad. |
| **Ficha del arriendo** | Datos del contrato; aviso y aplicación del reajuste por IPC; cobros con pagos (parciales, con comprobante), cargos adicionales y anulación; gastos y abonos a cargo del propietario (con respaldo); vista previa y emisión de la liquidación; término del arriendo con el estado de la garantía. |
| **Cobranza** | Cobros del mes con totales por cobrar, recaudado y pendiente; botón «Generar cobros del mes»; tabla de morosidad con los días de atraso. |
| **Liquidaciones** | Liquidaciones por período; emisión de todas las del mes; marcar como transferida (con referencia) o anular. |
| **Ficha del contacto** | Datos bancarios del propietario (privados) y sus arriendos, como propietario o como arrendatario. |
| **Ajustes** | Comisión de administración, IVA, día de generación de los cobros y datos para el pago. Fuente de la UF: manual, mindicador.cl o API de la CMF (con clave), y el botón «Actualizar la UF ahora». |

## Reglas

- **Cobros:** se genera uno por arriendo y mes, sin duplicarse, de forma automática con la tarea diaria o con el botón. El vencimiento es el día de pago (el último día si el mes es más corto). El primer y el último mes se prorratean.
- **Renta en UF:** se cobra en pesos con la UF del día del vencimiento, o la última conocida. El cobro guarda el valor, la fecha y la fuente usados.
- **Estados del cobro:** pendiente, pago parcial, pagado, vencido o anulado. No se acepta un pago mayor al saldo.
- **Reajuste IPC:**
  - al llegar la fecha, la ficha avisa y se ingresa el IPC acumulado publicado por el INE;
  - la nueva renta rige para los cobros siguientes;
  - la próxima fecha avanza 6 o 12 meses;
  - el historial registra la renta anterior, la nueva y la fuente.
- **Liquidación al propietario:** pagos recibidos y aún no liquidados − comisión (% del arriendo) − IVA sobre la comisión − gastos + abonos del período.
  - Un pago atrasado entra en la liquidación siguiente.
  - Al emitirse se numera (LIQ-2026-0001) y se congela el detalle. El PDF se guarda en el almacenamiento privado con su huella y se envía por correo al propietario.
  - Un gasto registrado después no la altera. Anularla (si no se ha transferido) libera sus pagos y gastos.
- **Permisos:** solo administración y el corredor responsable de la propiedad ven el arriendo, sus cobros y sus liquidaciones.

## Pruebas (WordPress 7.1.5 es_CL)

`pruebas/ejecutar.sh` ejecuta las tres suites: **Entrega 1: 33/33, Entrega 2: 23/23 y administración de arriendos: 21/21.**

Lo que cubre esta suite:

- cierre ganado del departamento en Ñuñoa → Arrendado;
- administración iniciada con renta de $550.000, garantía, partes y día de pago 5;
- cobro de diciembre sin duplicar;
- pagos de $300.000 (pago parcial) y $250.000 (pagado), con rechazo de un sobrepago;
- gasto de $40.000 y liquidación de $550.000 − $38.500 − $7.315 − $40.000 = **$464.185**, en PDF y transferida;
- una segunda liquidación del mismo mes, rechazada;
- un gasto posterior que no altera la liquidación emitida y entra en la siguiente;
- reajuste IPC de 2,1%: $561.550 desde enero, sin cambiar diciembre;
- renta de UF 16,5 en Providencia cobrada en pesos con la UF y su fuente;
- cobro vencido que aparece en morosidad y en el panel;
- otro corredor sin acceso (403).

## Pendiente

- **UF automática:** mindicador.cl respondía con error (HTTP 500) durante la prueba. La conexión está implementada pero no verificada en vivo; la carga manual funciona. La API de la CMF requiere pedir una clave gratuita.
- **Correos:** necesitan SMTP en el hosting.
- **Mejoras posibles:** intereses por mora, recordatorios automáticos antes del vencimiento, pago en línea (Khipu, Flow, Webpay), reparto de la liquidación entre varios titulares por porcentaje y certificado anual de rentas.
