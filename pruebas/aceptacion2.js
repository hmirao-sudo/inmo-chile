// Entrega 2 — Prueba de aceptación 3 (contrato de arriendo) y la parte de la prueba 4 que no depende de portales
// (cambio de precio, oferta, reserva y cierre). Se ejecuta después de aceptacion.js (usa su estado).
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = 'http://localhost:8080';
const CAP = path.join(__dirname, 'capturas');
const resultados = [];
// Si WordPress avisa que otra persona edita la ficha, tomar el control (comportamiento normal de WP).
async function tomarControl(pg) {
	const d = pg.locator('#post-lock-dialog a[href*="get-post-lock"]');
	if (await d.count() && await d.isVisible()) {
		await Promise.all([pg.waitForNavigation(), d.click()]);
	}
}

const db = (...a) => JSON.parse(execFileSync('php', [path.join(__dirname, 'consulta.php'), ...a], { encoding: 'utf8' }));
const ok = (n, c, d = '') => { resultados.push({ nombre: n, ok: !!c, detalle: d }); console.log(`${c ? 'PASA ' : 'FALLA'}  ${n}${d ? ' — ' + d : ''}`); };
const fecha = (dias) => new Date(Date.now() + dias * 86400000).toISOString().slice(0, 10);
const pdfTexto = (ruta) => execFileSync('pdftotext', ['-layout', ruta, '-'], { encoding: 'utf8' });

async function login(browser, u, p) {
	const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
	const pg = await ctx.newPage();
	await pg.goto(`${BASE}/wp-login.php`);
	await pg.fill('#user_login', u);
	await pg.fill('#user_pass', p);
	await Promise.all([pg.waitForNavigation(), pg.click('#wp-submit')]);
	return pg;
}
const enviar = (pg, sel) => Promise.all([pg.waitForNavigation(), pg.click(sel)]);

(async () => {
	const browser = await chromium.launch();
	const cam = await login(browser, 'camila.rojas', 'Prueba.Corredor.2026');
	const adm = await login(browser, 'admin', 'Admin.Demo.2026');
	const codigo = db('ultima_captacion').codigo; // Depto Ñuñoa en arriendo (prueba 1)
	const op = db('oportunidad', 'joaquin.fuentes@example.test', codigo);
	ok('3.0 La consulta y la visita de la prueba 2 generaron una oportunidad de arriendo', op && op.operacion === 'arriendo', `#${op.id} etapa ${op.etapa}`);

	// Tablero.
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-oportunidades`);
	await cam.screenshot({ path: `${CAP}/e2-01-tablero.png`, fullPage: true });
	ok('3.1 El tablero muestra la oportunidad en su etapa', (await cam.locator('.inmo-col--visita .inmo-card').count()) >= 1);

	// Condiciones del arriendo.
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-oportunidades&id=${op.id}`);
	await cam.fill('#c-condicionesfecha_inicio', fecha(15));
	await cam.fill('#c-condicionesdia_pago', '5');
	await cam.fill('#c-condicionesplazo_meses', '12');
	await enviar(cam, 'form:has(input[value="datos"]) button.button-primary');

	// Vista previa con faltantes.
	const pl = db('plantilla', 'arriendo');
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-documento&oportunidad=${op.id}&plantilla=${pl.id}`);
	const previa1 = await cam.textContent('#inmo-previa');
	const faltan1 = await cam.textContent('.inmo-faltantes');
	await cam.screenshot({ path: `${CAP}/e2-02-previa-faltantes.png`, fullPage: true });
	ok('3.2 La vista previa trae renta, garantía y partes correctas', /\$550\.000/.test(previa1) && /quinientos cincuenta mil pesos/.test(previa1) && /Valentina Pérez Lagos/.test(previa1) && /Joaquín Fuentes/.test(previa1) && /Pasaje Inventado 321, Depto 42/.test(previa1));
	ok('3.3 Señala los datos obligatorios que faltan', /RUT del arrendador/.test(faltan1) && /RUT del arrendatario/.test(faltan1), faltan1.replace(/\s+/g, ' ').trim().slice(0, 140));
	ok('3.4 El botón Emitir está deshabilitado', await cam.isDisabled('#inmo-emitir'));
	// Forzar el envío (como si alguien saltara la interfaz): el servidor también lo impide.
	await cam.evaluate(() => { document.getElementById('inmo-emitir').disabled = false; });
	await enviar(cam, '#inmo-emitir');
	const err = (await cam.locator('.notice-error').allTextContents()).join(' ');
	ok('3.5 Un dato obligatorio faltante impide emitir (validado en el servidor)', /No se puede emitir/.test(err) && db('emisiones').length === 0);

	// Completar RUT de las partes desde el CRM.
	const crm = db('contacto', 'joaquin.fuentes@example.test');
	const valen = db('contacto', 'valentina.perez@example.test');
	for (const [id, rut] of [[crm.contactos[0].id, '12.345.678-5'], [valen.contactos[0].id, '11.111.111-1']]) {
		await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-contactos&contacto=${id}`);
		await cam.fill('#c_rut', rut);
		await enviar(cam, 'form:has(input[value="datos"]) button.button-primary');
	}
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-documento&oportunidad=${op.id}&plantilla=${pl.id}`);
	ok('3.6 Con los datos completos se habilita la emisión', !(await cam.isDisabled('#inmo-emitir')));
	await enviar(cam, '#inmo-emitir');
	await cam.screenshot({ path: `${CAP}/e2-03-emitido.png`, fullPage: true });
	let em = db('emisiones');
	const e1 = em[0];
	const texto = pdfTexto(e1.ruta_pdf);
	ok('3.7 Se genera el PDF con número, renta, garantía y partes', /ARR-\d{4}-0001/.test(e1.numero) && /\$550\.000/.test(texto) && /Valentina Pérez Lagos/.test(texto) && /12\.345\.678-5/.test(texto) && /11\.111\.111-1/.test(texto) && /12 meses/.test(texto), e1.numero);
	execFileSync('pdftoppm', ['-png', '-r', '60', '-f', '1', '-l', '1', e1.ruta_pdf, path.join(CAP, 'e2-04-pdf-contrato')]);
	ok('3.8 El PDF queda en el expediente, íntegro y con firma pendiente', e1.integro && e1.estado_emision === 'emitido' && e1.estado_firma === 'pendiente');
	ok('3.9 La oportunidad avanza a «Contrato / promesa»', db('oportunidad', 'joaquin.fuentes@example.test', codigo).etapa === 'contrato');

	// Cambiar la plantilla después de emitir.
	await adm.goto(`${BASE}/wp-admin/post.php?post=${pl.id}&action=edit`);
	await tomarControl(adm);
	await adm.click('#content-html');
	const cont = await adm.inputValue('#content');
	const marca = `Cláusula de control ${Date.now()}`;
	await adm.fill('#content', cont + `\n<p><strong>Séptimo.</strong> ${marca}.</p>`);
	await enviar(adm, '#publish');
	const pl2 = db('plantilla', 'arriendo');
	em = db('emisiones');
	ok('3.10 La plantilla editada sube de versión', pl2.version === pl.version + 1, `v${pl.version} → v${pl2.version}`);
	ok('3.11 El documento ya emitido queda intacto', em[0].sha256 === e1.sha256 && em[0].sha_actual === e1.sha256 && Number(em[0].plantilla_version) === pl.version && !em[0].html.includes(marca) && !pdfTexto(em[0].ruta_pdf).includes(marca));
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-documento&oportunidad=${op.id}&plantilla=${pl.id}`);
	ok('3.12 Una nueva vista previa usa la versión nueva', (await cam.textContent('#inmo-previa')).includes(marca));

	// Firma: estados independientes del cierre.
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-documento&emision=${e1.id}`);
	await cam.selectOption('#fe-0', 'firmado');
	await cam.setInputFiles('#ff-0', path.join(__dirname, 'fotos', 'mandato-firmado-muestra.pdf'));
	await enviar(cam, 'form:has(#fe-0) button');
	ok('3.13 Firma del arrendador → firmado parcialmente', db('emisiones')[0].estado_firma === 'parcial');
	await cam.selectOption('#fe-1', 'firmado');
	await enviar(cam, 'form:has(#fe-1) button');
	await cam.screenshot({ path: `${CAP}/e2-05-firmas.png`, fullPage: true });
	const prop3 = db('propiedad', codigo);
	ok('3.14 Firmado por todas las partes sin cerrar la operación', db('emisiones')[0].estado_firma === 'firmado' && db('oportunidad', 'joaquin.fuentes@example.test', codigo).etapa === 'contrato' && prop3.estado === 'disponible');

	/* ---------------- Prueba 4 (parte de esta entrega) ---------------- */
	// Cambio de precio 5.200 → 5.100 UF.
	const casa = db('propiedad', 'CP-0001');
	await adm.goto(`${BASE}/wp-admin/post.php?post=${casa.id}&action=edit`);
	await tomarControl(adm);
	await adm.fill('#inmo_precio', '5100');
	await enviar(adm, 'button[name=save]');
	const pub = await browser.newPage();
	await pub.goto(casa.url);
	const hist = db('historial', 'CP-0001').map((h) => h.detalle).join(' | ');
	ok('4.1 El sitio muestra el nuevo precio en el mismo aviso (misma URL y código)', /UF 5\.100/.test(await pub.textContent('.ficha__precio-valor')) && db('propiedad', 'CP-0001').url === casa.url);
	ok('4.2 El historial registra el cambio de precio', /UF 5\.200 → UF 5\.100/.test(hist));

	// Dos interesados en la casa; uno ofrece, reserva y cierra.
	const joaquin = crm.contactos[0].id;
	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo-contactos&contacto=${joaquin}`);
	await adm.click('summary:has-text("Nueva oportunidad")');
	await adm.fill('#no_cod', 'CP-0001');
	await enviar(adm, 'form:has(input[value="nueva"]) button');
	const opv = db('oportunidad', 'joaquin.fuentes@example.test', 'CP-0001');
	const duena = db('contacto', 'mf.soto@example.test').contactos[0].id; // otro interesado ficticio (misma persona de prueba)
	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo-contactos&contacto=${duena}`);
	await adm.click('summary:has-text("Nueva oportunidad")');
	await adm.fill('#no_cod', 'CP-0001');
	await enviar(adm, 'form:has(input[value="nueva"]) button');

	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo-oportunidades&id=${opv.id}`);
	await adm.fill('#om', '5050');
	await adm.selectOption('#omo', 'UF');
	await adm.fill('#oc', 'Con crédito hipotecario preaprobado.');
	await enviar(adm, 'form:has(input[value="oferta"]) button');
	const ofSel = await adm.locator('select[id^="of-"]').first().getAttribute('id');
	await adm.selectOption(`#${ofSel}`, 'aceptada');
	await enviar(adm, `form:has(#${ofSel}) button`);
	await adm.fill('#rm', '100');
	await adm.selectOption('#rmo', 'UF');
	await enviar(adm, 'form:has(input[value="reserva"]) button');
	await pub.goto(casa.url);
	ok('4.3 Oferta aceptada y reserva: la propiedad queda Reservada en el sitio', db('propiedad', 'CP-0001').estado === 'reservada' && /Reservada/.test(await pub.textContent('.ficha__etiquetas')));
	await adm.screenshot({ path: `${CAP}/e2-06-oportunidad-venta.png`, fullPage: true });
	adm.once('dialog', (d) => d.accept());
	await enviar(adm, 'form:has(input[value="ganada"]) button');
	const casaFin = db('propiedad', 'CP-0001');
	const ops = db('oportunidades_propiedad', 'CP-0001');
	const r404 = await pub.goto(casa.url);
	ok('4.4 Al cerrar la venta la propiedad queda Vendida y sale del sitio', casaFin.estado === 'vendida' && r404.status() === 404, `${casaFin.estado}, HTTP ${r404.status()}`);
	ok('4.5 Las demás oportunidades de la casa se cierran como perdidas con motivo', ops.filter((o) => o.etapa === 'perdida' && /otro cliente/.test(o.motivo_perdida)).length === 1 && ops.filter((o) => o.etapa === 'ganada').length === 1);
	ok('4.6 El precio acordado es la oferta aceptada (UF 5.050)', /UF 5\.050/.test(await adm.textContent('.inmo-resumen')));

	// Expediente de la propiedad y permisos.
	await adm.goto(`${BASE}/wp-admin/post.php?post=${prop3.id}&action=edit`);
	await tomarControl(adm);
	await adm.screenshot({ path: `${CAP}/e2-07-expediente.png`, fullPage: true });
	ok('3.15 El expediente de la propiedad lista el contrato emitido', (await adm.textContent('#inmo_expediente')).includes(e1.numero));
	const die = await login(browser, 'diego.munoz', 'Prueba.Corredor.2026');
	const rOp = await die.goto(`${BASE}/wp-admin/admin.php?page=inmo-oportunidades&id=${op.id}`);
	const rDoc = await die.goto(`${BASE}/wp-admin/admin.php?page=inmo-documento&emision=${e1.id}`);
	ok('3.16 Otro corredor no ve la oportunidad ni el contrato', rOp.status() === 403 && rDoc.status() === 403, `${rOp.status()} / ${rDoc.status()}`);

	await browser.close();
	const f = resultados.filter((r) => !r.ok);
	console.log(`\n${resultados.length - f.length}/${resultados.length} verificaciones pasan.`);
	require('fs').writeFileSync(path.join(__dirname, 'resultado-aceptacion2.json'), JSON.stringify(resultados, null, 2));
	process.exit(f.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
