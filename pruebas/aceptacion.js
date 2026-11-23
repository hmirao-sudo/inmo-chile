// Pruebas de aceptación 1 y 2 (Entrega 1) contra el WordPress local, con navegador real.
// Uso: node aceptacion.js   (requiere el sitio en http://localhost:8080 y datos de prueba cargados)
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = 'http://localhost:8080';
const CAP = path.join(__dirname, 'capturas');
const FOTOS = path.join(__dirname, 'fotos');
const resultados = [];

function db(...args) {
	return JSON.parse(execFileSync('php', [path.join(__dirname, 'consulta.php'), ...args], { encoding: 'utf8' }));
}
function ok(nombre, cond, detalle = '') {
	resultados.push({ nombre, ok: !!cond, detalle });
	console.log(`${cond ? 'PASA ' : 'FALLA'}  ${nombre}${detalle ? ' — ' + detalle : ''}`);
}
function fecha(dias) {
	const d = new Date(Date.now() + dias * 86400000);
	return d.toISOString().slice(0, 10);
}
async function cookieSesion(ctx) {
	const c = (await ctx.cookies()).find((x) => x.name.startsWith('wordpress_logged_in_'));
	return decodeURIComponent(c.value);
}
async function login(browser, usuario, clave) {
	const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
	const p = await ctx.newPage();
	await p.goto(`${BASE}/wp-login.php`);
	await p.fill('#user_login', usuario);
	await p.fill('#user_pass', clave);
	await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
	return { ctx, p };
}
// Esperar a que pase la trampa de tiempo anti-bots (2 s).
const esperaHumana = (p) => p.waitForTimeout(2100);

(async () => {
	const browser = await chromium.launch();
	const movil = await browser.newContext({ ...devices['Pixel 7'] });
	const m = await movil.newPage();

	/* =================================================================
	 * PRUEBA 1 — Una propietaria entrega un departamento para arriendo en Ñuñoa
	 * ================================================================= */
	await m.goto(`${BASE}/publica-tu-propiedad/`);
	await m.screenshot({ path: `${CAP}/m-01-propietarios.png`, fullPage: true });
	await m.check('#propietario-operacion_propietario-arrendar');
	await m.selectOption('#propietario-tipo', 'departamento');
	await m.selectOption('#propietario-comuna_id', { label: 'Ñuñoa' });
	await m.fill('#propietario-sector', 'Plaza Ñuñoa');
	await m.fill('#propietario-dormitorios', '2');
	await m.fill('#propietario-banos', '1');
	await m.fill('#propietario-superficie', '55');
	await m.fill('#propietario-precio', '550000');
	await m.selectOption('#propietario-moneda', 'CLP');
	await m.fill('#propietario-nombre', 'Valentina Pérez Lagos (ficticia)');
	await m.fill('#propietario-email', 'valentina.perez@example.test');
	await m.fill('#propietario-telefono', '+56 9 6666 0202');
	await m.check('#propietario-consentimiento');
	await esperaHumana(m);
	await Promise.all([m.waitForNavigation(), m.click('#inmo-propietario button[type=submit]')]);
	const textoOk = await m.textContent('.inmo-alerta--ok');
	await m.screenshot({ path: `${CAP}/m-02-propietario-enviado.png`, fullPage: true });
	const cap = db('ultima_captacion');
	const codigo = cap.codigo;
	ok('1.1 La entrega crea una captación con código', /quedó en revisión/i.test(textoOk) && codigo, codigo);
	let prop = db('propiedad', codigo);
	ok('1.2 La captación queda en revisión y no publicada', prop.estado === 'en_revision' && prop.status === 'pending', `${prop.estado}/${prop.status}`);
	ok('1.3 La propietaria quedó como titular', prop.titulares.includes('Valentina Pérez Lagos (ficticia)'));

	// Invitación individual: completar características y subir fotos.
	const invitacion = await m.getAttribute('.inmo-alerta--ok a.inmo-boton', 'href');
	ok('1.4 Se entrega un enlace individual con vencimiento', /completar-propiedad\/\?t=/.test(invitacion));
	await m.goto(invitacion);
	await m.fill('#completar-c_gastos_comunes', '95000');
	await m.fill('#completar-c_garantia_meses', '1');
	await m.selectOption('#completar-c_reajuste', 'ipc_semestral');
	await m.fill('#completar-c_estacionamientos', '1');
	await m.check('input[name="c_equipamiento[]"][value="logia"]');
	await m.fill('#completar-c_descripcion', 'Departamento muy luminoso, a dos cuadras de Plaza Ñuñoa. (Texto de prueba)');
	await m.setInputFiles('#completar-fotos', [path.join(FOTOS, 'living.jpg'), path.join(FOTOS, 'cocina.jpg')]);
	await esperaHumana(m);
	await Promise.all([m.waitForNavigation(), m.click('#inmo-completar button[type=submit]')]);
	const textoComp = await m.textContent('.inmo-alerta--ok');
	ok('1.5 La propietaria completa la ficha y envía 2 fotos', /2 foto/.test(textoComp));
	await m.goto(invitacion);
	ok('1.6 El enlace no se puede reutilizar', /Ya recibimos/.test(await m.textContent('.inmo-completar')));
	prop = db('propiedad', codigo);
	ok('1.7 Las fotos quedan pendientes y fuera de la galería pública', prop.galeria.length === 0 && prop.docs.filter((d) => d.tipo === 'foto_propietario' && d.estado === 'pendiente').length === 2);

	// Antes de aprobar: no aparece en la búsqueda pública.
	await m.goto(`${BASE}/propiedades/?operacion=arriendo&comuna=nunoa`);
	ok('1.8 Antes de aprobarse NO aparece al filtrar arriendos en Ñuñoa', (await m.locator('.tarjeta').count()) === 0);
	const r404 = await m.goto(prop.url);
	ok('1.9 La ficha en revisión no es accesible al público', r404.status() === 404, `HTTP ${r404.status()}`);

	// Un corredor (Camila) no puede aprobar: solo envía a revisión.
	// Administración revisa, autoriza fotos, asigna corredora, registra mandato y aprueba.
	const adm = await login(browser, 'admin', 'Admin.Demo.2026');
	await adm.p.goto(`${BASE}/wp-admin/post.php?post=${prop.id}&action=edit`);
	await adm.p.screenshot({ path: `${CAP}/d-03-ficha-interna-revision.png`, fullPage: true });
	for (const cb of await adm.p.locator('input[name="inmo_autorizar_foto[]"]').all()) {
		await cb.check();
	}
	await adm.p.selectOption('#inmo_corredor', { label: 'Camila Rojas (ficticia)' });
	await adm.p.fill('#inmo_p_direccion', 'Pasaje Inventado 321');
	await adm.p.fill('#inmo_p_unidad', 'Depto 42');
	await adm.p.fill('#inmo_p_lat', '-33.4569');
	await adm.p.fill('#inmo_p_lng', '-70.5980');
	await adm.p.selectOption('#inmo_p_mandato_tipo', 'corretaje');
	await adm.p.fill('#inmo_p_comision_pct', '50');
	await adm.p.fill('#inmo_p_captacion_vence', fecha(90));
	await adm.p.selectOption('#inmo_doc_tipo', 'mandato');
	await adm.p.setInputFiles('input[name=inmo_doc]', path.join(FOTOS, 'mandato-firmado-muestra.pdf'));
	await Promise.all([adm.p.waitForNavigation(), adm.p.click('button[name=inmo_aprobar]')]);
	const aviso = await adm.p.locator('.notice').allTextContents();
	await adm.p.screenshot({ path: `${CAP}/d-04-ficha-interna-aprobada.png`, fullPage: true });
	prop = db('propiedad', codigo);
	ok('1.10 Tras aprobarse queda Disponible y publicada', prop.estado === 'disponible' && prop.status === 'publish', aviso.join(' ').replace(/\s+/g, ' ').slice(0, 120));
	ok('1.11 Solo las fotos autorizadas pasan a la galería', prop.galeria.length === 2);

	// Visitante: filtro arriendos en Ñuñoa.
	await m.goto(`${BASE}/`);
	await m.screenshot({ path: `${CAP}/m-05-portada.png`, fullPage: true });
	await m.check('.buscador--portada input[value=arriendo]', { force: true });
	await m.selectOption('#b-portada-comuna', 'nunoa');
	await Promise.all([m.waitForNavigation(), m.click('.buscador--portada .buscador__enviar')]);
	const urlBusqueda = m.url();
	const tarjetas = await m.locator('.tarjeta').count();
	const precioTarjeta = await m.locator('.tarjeta__precio').first().textContent();
	await m.screenshot({ path: `${CAP}/m-06-resultados-nunoa.png`, fullPage: true });
	ok('1.12 Aparece al filtrar arriendos de Ñuñoa', tarjetas === 1 && /\$550\.000/.test(precioTarjeta), `${urlBusqueda.replace(BASE, '')} → ${tarjetas} resultado(s), ${precioTarjeta.trim()}`);
	await Promise.all([m.waitForNavigation(), m.click('.tarjeta__titulo a')]);
	const html = await m.content();
	const fotosFicha = await m.locator('.galeria__slide').count();
	await m.screenshot({ path: `${CAP}/m-07-ficha-publica.png`, fullPage: true });
	ok('1.13 El visitante ve el precio y solo las fotos autorizadas', /\$550\.000/.test(html) && fotosFicha === 2, `${fotosFicha} fotos`);
	ok('1.14 La ficha pública no expone dirección, titular, RUT ni mandato', !/Pasaje Inventado|Valentina|mandato-firmado|-33\.4569|-70\.598\b/.test(html));
	// Volver conserva filtros.
	await m.click('[data-volver-resultados]');
	await m.waitForLoadState();
	ok('1.15 «Volver a los resultados» conserva los filtros', m.url() === urlBusqueda, m.url().replace(BASE, ''));

	// Solo el corredor autorizado abre el mandato.
	const mandato = prop.docs.find((d) => d.tipo === 'mandato');
	const perms = db('permisos_doc', mandato.id);
	ok('1.16 Regla: admin y corredora responsable pueden abrir el mandato; otro corredor y visitantes no',
		perms.admin.puede_abrir_documento && perms['camila.rojas'].puede_abrir_documento && !perms['diego.munoz'].puede_abrir_documento && !perms.anonimo.puede_abrir_documento,
		JSON.stringify(perms));
	const cam = await login(browser, 'camila.rojas', 'Prueba.Corredor.2026');
	const dCam = db('descargar_como', mandato.id, 'camila.rojas');
	ok('1.17 Endpoint de descarga: la corredora responsable obtiene el PDF', dCam.http === 200 && dCam.es_pdf, JSON.stringify(dCam));
	// Además, el enlace visible en su ficha interna funciona.
	await cam.p.goto(`${BASE}/wp-admin/post.php?post=${prop.id}&action=edit`);
	const hrefUI = await cam.p.getAttribute('#inmo_captacion a[href*="action=inmo_documento"]', 'href');
	const rUI = await cam.p.request.get(hrefUI);
	ok('1.17b El enlace del mandato en la ficha interna de la corredora descarga el PDF', rUI.status() === 200, `HTTP ${rUI.status()}`);
	const die = await login(browser, 'diego.munoz', 'Prueba.Corredor.2026');
	const dDie = db('descargar_como', mandato.id, 'diego.munoz');
	ok('1.18 Endpoint de descarga: otro corredor recibe 403 aun con un nonce válido', dDie.http === 403, JSON.stringify(dDie));
	const rEdit = await die.p.goto(`${BASE}/wp-admin/post.php?post=${prop.id}&action=edit`);
	ok('1.19 Otro corredor no puede abrir la ficha interna', rEdit.status() >= 400 && /not allowed|no tienes permiso/i.test(await die.p.content()), `HTTP ${rEdit.status()} (mensaje de WordPress)`);
	const anon = await browser.newContext();
	const rAnon = await (await anon.newPage()).request.get(hrefUI, { maxRedirects: 0 });
	ok('1.20 Visitante sin sesión no obtiene el mandato', rAnon.status() !== 200 || !/pdf/.test(rAnon.headers()['content-type'] || ''), `HTTP ${rAnon.status()}`);

	/* =================================================================
	 * PRUEBA 2 — Un interesado consulta por dos inmuebles y pide visita desde el celular
	 * ================================================================= */
	const interesado = { nombre: 'Joaquín Fuentes (ficticio)', email: 'joaquin.fuentes@example.test', tel: '+56 9 7777 0303' };
	async function consultar(url, mensaje) {
		await m.goto(url);
		await m.fill('#consulta-nombre', interesado.nombre);
		await m.fill('#consulta-email', interesado.email);
		await m.fill('#consulta-telefono', interesado.tel);
		await m.fill('#consulta-mensaje', mensaje);
		await m.check('#consulta-consentimiento');
		await esperaHumana(m);
		await Promise.all([m.waitForNavigation(), m.click('#inmo-consulta button[type=submit]')]);
		return m.textContent('.inmo-alerta--ok');
	}
	const c1 = await consultar(prop.url, '¿Se aceptan mascotas?');
	ok('2.1 Consulta por el depto de Ñuñoa: confirma y lleva el código', c1.includes(codigo), c1.trim().slice(0, 90));
	const otra = db('propiedad', 'CP-0002');
	const c2 = await consultar(otra.url, '¿Incluye estacionamiento?');
	ok('2.2 Consulta por otra propiedad (CP-0002)', c2.includes('CP-0002'));

	// Visita desde el celular.
	await m.goto(prop.url);
	await m.click('.ficha__acciones-movil [data-inmo-abrir-tab="inmo-tab-visita"]');
	await m.fill('#visita-nombre', interesado.nombre);
	await m.fill('#visita-email', interesado.email.toUpperCase()); // misma persona, distinto formato
	await m.fill('#visita-telefono', '9 7777 0303');
	await m.fill('#visita-fecha', fecha(3));
	await m.selectOption('#visita-franja', 'tarde');
	await m.check('#visita-consentimiento');
	await m.screenshot({ path: `${CAP}/m-08-solicitar-visita.png`, fullPage: false });
	await esperaHumana(m);
	await Promise.all([m.waitForNavigation(), m.click('#inmo-visita button[type=submit]')]);
	const c3 = await m.textContent('.inmo-alerta--ok');
	await m.screenshot({ path: `${CAP}/m-09-visita-pendiente.png`, fullPage: false });
	ok('2.3 La visita queda PENDIENTE y se explica qué ocurrirá', /PENDIENTE DE CONFIRMACIÓN/.test(c3) && c3.includes(codigo));

	let crm = db('contacto', interesado.email);
	const propsConsultadas = new Set(crm.consultas.map((q) => q.codigo));
	ok('2.4 El CRM conserva UN contacto', crm.contactos.length === 1, `${crm.contactos.length} contacto(s)`);
	ok('2.5 …con sus consultas por dos inmuebles', crm.consultas.length === 3 && propsConsultadas.size === 2, [...propsConsultadas].join(', '));
	ok('2.6 Roles derivados: arrendatario', /arrendatario/.test(crm.contactos[0].roles), crm.contactos[0].roles);

	// Ficha del contacto agrupada por inmueble (como corredora responsable).
	await cam.p.goto(`${BASE}/wp-admin/admin.php?page=inmo-contactos&contacto=${crm.contactos[0].id}`);
	await cam.p.screenshot({ path: `${CAP}/d-10-crm-contacto.png`, fullPage: true });
	const grupos = await cam.p.locator('.inmo-caja h3.inmo-subtitulo').allTextContents();
	ok('2.7 La ficha del contacto muestra el historial por inmueble', grupos.some((g) => g.includes(codigo)) && grupos.some((g) => g.includes('CP-0002')), grupos.join(' | '));
	const rDieC = await die.p.goto(`${BASE}/wp-admin/admin.php?page=inmo-contactos&contacto=${crm.contactos[0].id}`);
	ok('2.8 Un corredor sin relación no ve el contacto', rDieC.status() === 403 && !/joaquin/i.test(await die.p.content()), `HTTP ${rDieC.status()}`);

	// Confirmar y reprogramar la visita desde la ficha interna.
	const visita = crm.consultas.find((q) => q.tipo === 'visita');
	await cam.p.goto(`${BASE}/wp-admin/post.php?post=${prop.id}&action=edit`);
	await cam.p.selectOption(`select[name="inmo_q[${visita.id}][estado]"]`, 'confirmada');
	await cam.p.fill(`input[name="inmo_q[${visita.id}][fecha]"]`, `${fecha(3)}T17:30`);
	await Promise.all([cam.p.waitForNavigation(), cam.p.click(`button[name=inmo_q_aplicar][value="${visita.id}"]`)]);
	crm = db('contacto', interesado.email);
	let v = crm.consultas.find((q) => q.id === visita.id);
	ok('2.9 La corredora confirma la visita desde la ficha', v.estado === 'confirmada' && v.visita_fecha.startsWith(`${fecha(3)} 17:30`), `${v.estado} ${v.visita_fecha}`);
	await cam.p.selectOption(`select[name="inmo_q[${visita.id}][estado]"]`, 'reprogramada');
	await cam.p.fill(`input[name="inmo_q[${visita.id}][fecha]"]`, `${fecha(4)}T11:00`);
	await cam.p.fill(`input[name="inmo_q[${visita.id}][nota]"]`, 'La propietaria no puede el jueves.');
	await Promise.all([cam.p.waitForNavigation(), cam.p.click(`button[name=inmo_q_aplicar][value="${visita.id}"]`)]);
	await cam.p.screenshot({ path: `${CAP}/d-11-visita-reprogramada.png`, fullPage: true });
	crm = db('contacto', interesado.email);
	v = crm.consultas.find((q) => q.id === visita.id);
	ok('2.10 …o la reprograma', v.estado === 'reprogramada' && v.visita_fecha.startsWith(`${fecha(4)} 11:00`), `${v.estado} ${v.visita_fecha}`);
	const prop2 = db('propiedad', codigo);
	ok('2.11 La ficha interna sigue en Disponible (la corredora no cambió el estado al guardar)', prop2.estado === 'disponible' && prop2.status === 'publish');

	// Extra: búsqueda sin resultados en móvil + consulta general.
	await m.goto(`${BASE}/propiedades/?operacion=venta&comuna=vitacura&tipo=terreno`);
	await m.screenshot({ path: `${CAP}/m-12-sin-resultados.png`, fullPage: true });
	ok('2.12 Caso sin resultados ofrece quitar filtros y dejar una consulta general', (await m.locator('.sugerencias a').count()) >= 2 && (await m.locator('#inmo-general form').count()) === 1);

	// Capturas de escritorio.
	const esc = await browser.newContext({ viewport: { width: 1366, height: 900 } });
	const e = await esc.newPage();
	await e.goto(`${BASE}/`);
	await e.screenshot({ path: `${CAP}/d-01-portada.png`, fullPage: true });
	await e.goto(`${BASE}/propiedades/`);
	await e.screenshot({ path: `${CAP}/d-02-resultados.png`, fullPage: true });
	await e.goto(otra.url);
	await e.screenshot({ path: `${CAP}/d-05-ficha-publica.png`, fullPage: true });
	await adm.p.goto(`${BASE}/wp-admin/admin.php?page=inmo`);
	await adm.p.screenshot({ path: `${CAP}/d-12-panel.png`, fullPage: true });
	await adm.p.goto(`${BASE}/wp-admin/edit.php?post_type=inmo_propiedad`);
	await adm.p.screenshot({ path: `${CAP}/d-13-listado-propiedades.png`, fullPage: true });

	await browser.close();
	const fallas = resultados.filter((r) => !r.ok);
	console.log(`\n${resultados.length - fallas.length}/${resultados.length} verificaciones pasan.`);
	require('fs').writeFileSync(path.join(__dirname, 'resultado-aceptacion.json'), JSON.stringify(resultados, null, 2));
	process.exit(fallas.length ? 1 : 0);
})().catch((err) => {
	console.error(err);
	process.exit(2);
});
