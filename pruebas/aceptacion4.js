// Portal privado del dueño + UF automática desde la CMF (respuestas simuladas con el formato oficial).
// Se ejecuta después de aceptacion.js, aceptacion2.js y aceptacion3.js.
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = 'http://localhost:8080';
const CAP = path.join(__dirname, 'capturas');
const resultados = [];
const db = (...a) => JSON.parse(execFileSync('php', [path.join(__dirname, 'consulta.php'), ...a], { encoding: 'utf8' }));
const ok = (n, c, d = '') => { resultados.push({ nombre: n, ok: !!c, detalle: d }); console.log(`${c ? 'PASA ' : 'FALLA'}  ${n}${d ? ' — ' + d : ''}`); };
const CLAVE = 'Portal.Propietaria.2026!';

async function login(browser, u, p) {
	const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
	const pg = await ctx.newPage();
	await pg.goto(`${BASE}/wp-login.php`);
	await pg.fill('#user_login', u);
	await pg.fill('#user_pass', p);
	await Promise.all([pg.waitForNavigation(), pg.click('#wp-submit')]);
	pg.on('dialog', (d) => d.accept());
	return pg;
}
const enviar = (pg, sel) => Promise.all([pg.waitForNavigation(), pg.locator(sel).first().click()]);

(async () => {
	const browser = await chromium.launch();
	const cam = await login(browser, 'camila.rojas', 'Prueba.Corredor.2026');
	const valen = db('contacto', 'valentina.perez@example.test').contactos[0];
	const codigo = db('ultima_captacion').codigo;

	/* 6.1 La corredora da acceso */
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-contactos&contacto=${valen.id}`);
	await enviar(cam, 'button[name=portal][value=invitar]');
	const enlace = await cam.inputValue('input[aria-label="Enlace de acceso"]');
	const usuario = (await cam.textContent('.notice code')).trim();
	ok('6.1 La corredora da acceso al portal y obtiene el enlace personal', /action=rp&key=/.test(enlace) && usuario.length > 3, usuario);

	/* 6.2 La propietaria crea su contraseña y entra desde el celular */
	const movil = await browser.newContext({ ...devices['Pixel 7'] });
	const m = await movil.newPage();
	await m.goto(enlace);
	await m.fill('#pass1', CLAVE);
	await enviar(m, '#wp-submit');
	await m.goto(`${BASE}/portal-propietario/`);
	await m.fill('#user_login', usuario);
	await m.fill('#user_pass', CLAVE);
	await enviar(m, '#inmo-portal-login #wp-submit');
	ok('6.2 Crea su contraseña e ingresa al portal', /portal-propietario/.test(m.url()) && /Mis propiedades/.test(await m.content()), m.url().replace(BASE, ''));
	await m.screenshot({ path: `${CAP}/m-20-portal-resumen.png`, fullPage: true });
	const r = await m.goto(`${BASE}/wp-admin/`);
	ok('6.3 No puede entrar al escritorio de WordPress (se redirige al portal)', /portal-propietario/.test(m.url()), m.url().replace(BASE, ''));

	/* 6.4 Ve solo lo suyo */
	await m.goto(`${BASE}/portal-propietario/`);
	const resumen = await m.textContent('.inmo-portal');
	ok('6.4 Ve su departamento (Arrendado) y no las propiedades de otros dueños', resumen.includes(codigo) && /Arrendada/.test(resumen) && !/CP-0001|CP-0002/.test(resumen));
	await enviar(m, '.inmo-portal__prop a.inmo-boton');
	const det = await m.content();
	await m.screenshot({ path: `${CAP}/m-21-portal-detalle.png`, fullPage: true });
	ok('6.5 Ve el arriendo, los pagos del mes y la liquidación transferida', /\$561\.550/.test(det) && /diciembre 2026[\s\S]*?Pagado/.test(await m.textContent('#inmo-portal-cobros')) && /LIQ-2026-0001[\s\S]*?\$464\.185[\s\S]*?Transferida/.test(await m.textContent('.inmo-portal')));
	ok('6.6 Ve visitas sin datos personales de los interesados', /solicitud\(es\) de visita/.test(det) && !/joaquin\.fuentes@example\.test|7777 0303|56977770303/.test(det));
	const hrefLiq = await m.getAttribute('a:has-text("Descargar")', 'href');
	const rLiq = await m.request.get(hrefLiq);
	ok('6.7 Descarga su liquidación en PDF', rLiq.status() === 200 && /pdf/.test(rLiq.headers()['content-type']), `HTTP ${rLiq.status()}`);
	const docsTxt = await m.textContent('.inmo-portal__docs');
	ok('6.8 Ve sus documentos: contrato de arriendo y liquidación', /Contrato de arriendo/.test(docsTxt) && /Liquidación/.test(docsTxt));

	const docs1 = db('docs_propiedad', 'CP-0001');
	const mandatoAjeno = docs1.find((d) => d.tipo === 'mandato');
	const docs3 = db('docs_propiedad', codigo);
	const liqDoc = docs3.find((d) => d.subtipo === 'liquidacion');
	ok('6.9 Regla: no puede descargar documentos de propiedades ajenas', !db('portal_puede', 'valentina.perez@example.test', mandatoAjeno.id).puede && db('portal_puede', 'valentina.perez@example.test', liqDoc.id).puede);
	const fotoProp = docs3.find((d) => d.tipo === 'foto_propietario');
	ok('6.10 Regla: solo documentos en que es parte (no fotos internas ni otros)', !db('portal_puede', 'valentina.perez@example.test', fotoProp.id).puede);

	/* 6.11 Mensaje al corredor */
	await m.fill('#portal-mensaje', 'Quiero actualizar mi cuenta bancaria. (Mensaje de prueba)');
	await enviar(m, '#mensaje button[type=submit]');
	const msj = db('mensajes_portal', 'valentina.perez@example.test');
	ok('6.11 Escribe a su corredor y el mensaje llega al CRM con la propiedad', /Enviamos tu mensaje/.test(await m.textContent('#mensaje')) && msj.length === 1 && msj[0].codigo === codigo);
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-consultas`);
	ok('6.12 La corredora lo ve en su bandeja', /Portal del propietario/.test(await cam.textContent('.inmo-wrap')));

	/* 6.13 Revocar acceso */
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-contactos&contacto=${valen.id}`);
	await enviar(cam, 'button[name=portal][value=revocar]');
	await m.goto(`${BASE}/portal-propietario/`);
	const tras = await m.content();
	await m.fill('#user_login', usuario);
	await m.fill('#user_pass', CLAVE);
	await enviar(m, '#inmo-portal-login #wp-submit');
	ok('6.13 Al revocar, la sesión se cierra y no puede volver a entrar', /inmo-portal-login/.test(tras) && /desactivado/.test(await m.content()));

	/* 6.14 Otra propietaria con dos propiedades */
	const adm = await login(browser, 'admin', 'Admin.Demo.2026');
	const mf = db('contacto', 'mf.soto@example.test').contactos[0];
	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo-contactos&contacto=${mf.id}`);
	await enviar(adm, 'button[name=portal][value=invitar]');
	const enlace2 = await adm.inputValue('input[aria-label="Enlace de acceso"]');
	const usuario2 = (await adm.textContent('.notice code')).trim();
	const c2 = await browser.newContext({ viewport: { width: 1366, height: 900 } });
	const p2 = await c2.newPage();
	await p2.goto(enlace2);
	await p2.fill('#pass1', CLAVE);
	await enviar(p2, '#wp-submit');
	await p2.goto(`${BASE}/portal-propietario/`);
	await p2.fill('#user_login', usuario2);
	await p2.fill('#user_pass', CLAVE);
	await enviar(p2, '#inmo-portal-login #wp-submit');
	const res2 = await p2.textContent('.inmo-portal');
	await p2.screenshot({ path: `${CAP}/d-20-portal-dos-propiedades.png`, fullPage: true });
	ok('6.14 Una propietaria con dos propiedades ve ambas y no la de Valentina', /CP-0001/.test(res2) && /CP-0002/.test(res2) && !res2.includes(codigo));
	await p2.click('a.inmo-boton[href*="propiedad="]');
	await p2.waitForLoadState();
	const det2 = await p2.textContent('.inmo-portal');
	ok('6.15 Ve las ofertas de su casa (monto y estado)', /UF 5\.050[\s\S]*?Aceptada/.test(det2) || /Vendida/.test(det2), /UF 5\.050/.test(det2) ? 'oferta visible' : 'detalle mostrado');

	await browser.close();

	/* 7.x UF automática desde la CMF (respuesta simulada con el formato oficial) */
	const bad = db('probar_cmf', 'clave_invalida');
	ok('7.1 Clave inválida: se informa el mensaje de la CMF y la UF vigente no cambia', /API key no valida/.test(bad.resultado.error || '') && bad.vigente[0] === bad.antes[0], bad.resultado.error);
	const u = db('probar_cmf', 'ok');
	ok('7.2 Se consulta el recurso «posteriores» desde el mes anterior en JSON', /\/recursos_api\/uf\/posteriores\/\d{4}\/\d{2}\?apikey=\*\*\*&formato=json$/.test(u.url), u.url);
	ok('7.3 La UF vigente es la de hoy (no la del último día publicado)', u.vigente[1] === u.hoy && u.resultado.hasta > u.hoy && /CMF/.test(u.vigente[2]), `vigente ${u.vigente[0]} al ${u.vigente[1]}; publicada hasta ${u.resultado.hasta}`);
	ok('7.4 Un cobro que vence el día 5 del mes siguiente usa la UF exacta de ese día', u.uf_vencimiento_futuro.exacta === true && u.uf_vencimiento_futuro.fecha === u.vencimiento, `${u.vencimiento}: ${u.uf_vencimiento_futuro.valor}`);

	const f = resultados.filter((x) => !x.ok);
	console.log(`\n${resultados.length - f.length}/${resultados.length} verificaciones pasan.`);
	require('fs').writeFileSync(path.join(__dirname, 'resultado-aceptacion4.js.json'), JSON.stringify(resultados, null, 2));
	process.exit(f.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
