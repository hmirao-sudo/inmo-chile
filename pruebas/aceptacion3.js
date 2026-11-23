// Administración de arriendos: cobranza mensual y liquidaciones a propietarios.
// Se ejecuta después de aceptacion.js y aceptacion2.js (usa su estado).
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = 'http://localhost:8080';
const CAP = path.join(__dirname, 'capturas');
const resultados = [];
const db = (...a) => JSON.parse(execFileSync('php', [path.join(__dirname, 'consulta.php'), ...a], { encoding: 'utf8' }));
const ok = (n, c, d = '') => { resultados.push({ nombre: n, ok: !!c, detalle: d }); console.log(`${c ? 'PASA ' : 'FALLA'}  ${n}${d ? ' — ' + d : ''}`); };
const pdfTexto = (ruta) => execFileSync('pdftotext', ['-layout', ruta, '-'], { encoding: 'utf8' });
const hoy = new Date().toISOString().slice(0, 10);

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
const aviso = async (pg) => (await pg.locator('.notice').allTextContents()).join(' ').replace(/\s+/g, ' ').trim();

(async () => {
	const browser = await chromium.launch();
	const cam = await login(browser, 'camila.rojas', 'Prueba.Corredor.2026');
	const codigo = db('ultima_captacion').codigo; // Depto Ñuñoa (CP-0003)
	const op = db('oportunidad', 'joaquin.fuentes@example.test', codigo);

	/* 5.1 Iniciar la administración desde la oportunidad ganada */
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-oportunidades&id=${op.id}`);
	await enviar(cam, 'form:has(input[value="ganada"]) button');
	ok('5.0 Cierre ganado: el departamento queda Arrendado', db('propiedad', codigo).estado === 'arrendada');
	await enviar(cam, 'a:has-text("Iniciar administración")');
	ok('5.1 El formulario trae renta, partes y garantía desde la oportunidad y el contrato',
		(await cam.inputValue('#n-renta')) === '550000' && (await cam.inputValue('#n-gar')) === '550000' &&
		/Joaquín/.test(await cam.locator('#n-arrendatario_id option:checked').textContent()) &&
		/Valentina/.test(await cam.locator('#n-beneficiario_id option:checked').textContent()) &&
		(await cam.inputValue('#n-dia')) === '5');
	await cam.fill('#n-ini', '2026-12-01');
	await cam.fill('#n-fin', '2027-11-30');
	await enviar(cam, 'form:has(input[value="crear"]) button');
	let a = db('arriendo', codigo);
	ok('5.2 Arriendo administrado vigente', a && a.estado === 'vigente' && Number(a.renta) === 550000 && Number(a.comision_pct) === 7, a ? `renta ${a.renta}, comisión ${a.comision_pct}%, próximo reajuste ${a.proximo_reajuste}` : '');

	/* 5.3 Cobro mensual idempotente */
	await cam.fill('#g-p', '2026-12');
	await enviar(cam, 'form:has(input[value="generar_cobro"]) button');
	await cam.fill('#g-p', '2026-12');
	await enviar(cam, 'form:has(input[value="generar_cobro"]) button');
	a = db('arriendo', codigo);
	ok('5.3 Cobro de diciembre por $550.000 con vencimiento el día 5, sin duplicarse', a.cobros.length === 1 && a.cobros[0].total === '550000' && a.cobros[0].vence === '2026-12-05' && a.cobros[0].estado === 'pendiente', JSON.stringify(a.cobros[0]).slice(0, 120));

	/* 5.4 Pagos parciales */
	const cid = a.cobros[0].id;
	const pagar = async (monto, ref) => {
		await cam.click(`#cobro-${cid} summary:has-text("Registrar pago")`);
		await cam.fill(`#p-m-${cid}`, String(monto));
		await cam.fill(`#p-r-${cid}`, ref);
		await enviar(cam, `#cobro-${cid} form:has(input[value="pago"]) button`);
	};
	await pagar(300000, 'TRX-001');
	ok('5.4 Pago de $300.000 → pago parcial', db('arriendo', codigo).cobros[0].estado === 'parcial');
	await pagar(260000, 'TRX-002');
	ok('5.5 Un pago mayor al saldo se rechaza', /supera el saldo/.test(await aviso(cam)) && db('arriendo', codigo).cobros[0].pagado === '300000');
	await pagar(250000, 'TRX-002');
	ok('5.6 Pago del saldo → pagado', db('arriendo', codigo).cobros[0].estado === 'pagado' && db('arriendo', codigo).cobros[0].pagado === '550000');

	/* 5.7 Gasto y liquidación */
	await cam.fill('#m-f', '2026-12-10');
	await cam.fill('#m-c', 'Reparación calefont');
	await cam.fill('#m-m', '40000');
	await enviar(cam, 'form:has(input[value="movimiento"]) button');
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-arriendos&id=${a.id}&liq_previa=2026-12`);
	const neto = (await cam.textContent('#inmo-liq-neto')).trim();
	const previa = await cam.textContent('#inmo-liq-previa');
	ok('5.7 Liquidación: $550.000 − comisión 7% ($38.500) − IVA ($7.315) − gasto ($40.000) = $464.185', neto === '$464.185' && /-\$38\.500/.test(previa) && /-\$7\.315/.test(previa) && /-\$40\.000/.test(previa), neto);
	await enviar(cam, '#inmo-emitir-liq');
	await cam.screenshot({ path: `${CAP}/e3-01-arriendo.png`, fullPage: true });
	a = db('arriendo', codigo);
	const liq = a.liquidaciones[0];
	const txt = pdfTexto(liq.ruta_pdf);
	ok('5.8 Se emite la liquidación en PDF para la propietaria', /LIQ-\d{4}-0001/.test(liq.numero) && /\$464\.185/.test(txt) && /Valentina/.test(txt) && /Reparación calefont/.test(txt), liq.numero);
	execFileSync('pdftoppm', ['-png', '-r', '60', '-f', '1', '-l', '1', liq.ruta_pdf, path.join(CAP, 'e3-02-pdf-liquidacion')]);
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-arriendos&id=${a.id}&liq_previa=2026-12`);
	ok('5.9 No se puede liquidar dos veces el mismo período', /ya tiene la liquidación/.test(await cam.textContent('.inmo-wrap')));

	// Un gasto posterior no altera la liquidación emitida; se imputa a la siguiente.
	await cam.fill('#m-f', '2027-01-08');
	await cam.fill('#m-c', 'Cambio de chapa');
	await cam.fill('#m-m', '25000');
	await enviar(cam, 'form:has(input[value="movimiento"]) button');
	a = db('arriendo', codigo);
	ok('5.10 La liquidación emitida queda intacta (montos y PDF)', a.liquidaciones[0].neto === '464185' && a.liquidaciones[0].sha_actual === liq.sha256);
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-arriendos&id=${a.id}&liq_previa=2027-01`);
	ok('5.11 El gasto nuevo aparece en la liquidación del mes siguiente', /Cambio de chapa/.test(await cam.textContent('#inmo-liq-previa')));

	// Transferencia al propietario.
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-liquidaciones`);
	await cam.click('summary:has-text("Marcar transferida")');
	await cam.fill(`#lp-r-${liq.id}`, 'TEF-7788');
	await enviar(cam, `form:has(#lp-r-${liq.id}) button`);
	ok('5.12 La liquidación queda transferida con referencia', db('arriendo', codigo).liquidaciones[0].estado === 'pagada');

	/* 5.13 Reajuste por IPC: rige solo para los cobros siguientes */
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-arriendos&id=${a.id}`);
	const pg = cam;
	// Aplicación anticipada desde PHP no expuesta: se usa el formulario del aviso cuando corresponde.
	// Para la prueba se adelanta la fecha de reajuste al día de hoy.
	execFileSync('mysql', ['wp_inmo', '-e', `UPDATE wp_inmo_arriendos SET proximo_reajuste='${hoy}' WHERE id=${a.id}`]);
	await pg.reload();
	ok('5.13 Aviso de reajuste pendiente al llegar la fecha', /Reajuste pendiente/.test(await pg.textContent('.inmo-wrap')));
	await pg.fill('#r-pct', '2,1');
	await enviar(pg, 'form:has(input[value="reajuste"]) button');
	await pg.fill('#g-p', '2027-01');
	await enviar(pg, 'form:has(input[value="generar_cobro"]) button');
	a = db('arriendo', codigo);
	ok('5.14 IPC 2,1%: la renta pasa a $561.550 desde el cobro siguiente; diciembre no cambia', Number(a.renta) === 561550 && a.cobros[0].total === '550000' && a.cobros[1].total === '561550', `${a.cobros.map((c) => c.periodo + ' ' + c.total).join(', ')}`);

	/* 5.15 Renta en UF y morosidad (Providencia, CP-0002) */
	const adm = await login(browser, 'admin', 'Admin.Demo.2026');
	const p2 = db('propiedad', 'CP-0002');
	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo-arriendos&nuevo=1&propiedad=${p2.id}`);
	await adm.selectOption('#n-arrendatario_id', { label: 'Joaquín Fuentes (ficticio)' });
	await adm.fill('#n-renta', '16,5');
	await adm.selectOption('#n-moneda', 'UF');
	await adm.selectOption('#n-reaj', 'uf');
	await adm.fill('#n-ini', '2026-10-01');
	await enviar(adm, 'form:has(input[value="crear"]) button');
	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo-cobranza&periodo=2026-11`);
	await enviar(adm, 'form:has(input[value="generar_periodo"]) button');
	const a2 = db('arriendo', 'CP-0002');
	const k = a2.cobros.find((c) => c.periodo === '2026-11');
	ok('5.15 Renta de UF 16,5 se cobra en pesos con la UF y su fuente', k && k.total === String(Math.round(16.5 * Number(k.uf_valor))) && /UF \$/.test(k.nota), k ? `${k.total} (${k.nota})` : '');
	ok('5.16 Cobro con vencimiento pasado y sin pago queda Vencido', k && k.estado === 'vencido');
	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo-cobranza&periodo=2026-11`);
	await adm.screenshot({ path: `${CAP}/e3-03-cobranza.png`, fullPage: true });
	ok('5.17 La morosidad lista al arrendatario con días de atraso', /CP-0002/.test(await adm.textContent('#inmo-morosidad')));
	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo`);
	ok('5.18 El panel muestra cobros vencidos y liquidaciones', /cobros de arriendo vencidos/.test(await adm.textContent('.inmo-kpis')));
	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo-cobranza&periodo=2026-11`);
	await enviar(adm, 'form:has(input[value="generar_periodo"]) button');
	ok('5.19 Generar el período de nuevo no duplica cobros', db('arriendo', 'CP-0002').cobros.filter((c) => c.periodo === '2026-11').length === 1 && /0 cobro\(s\) generado/.test(await aviso(adm)));

	/* 5.20 Permisos */
	const die = await login(browser, 'diego.munoz', 'Prueba.Corredor.2026');
	const r = await die.goto(`${BASE}/wp-admin/admin.php?page=inmo-arriendos&id=${a.id}`);
	await die.goto(`${BASE}/wp-admin/admin.php?page=inmo-cobranza&periodo=2026-12`);
	const txtDie = await die.textContent('.inmo-wrap');
	await die.goto(`${BASE}/wp-admin/admin.php?page=inmo-liquidaciones`);
	const txtDieL = await die.textContent('.inmo-wrap');
	ok('5.20 Otro corredor no ve el arriendo, sus cobros ni sus liquidaciones', r.status() === 403 && !txtDie.includes(codigo) && !txtDieL.includes('LIQ-'), `HTTP ${r.status()}`);

	await browser.close();
	const f = resultados.filter((x) => !x.ok);
	console.log(`\n${resultados.length - f.length}/${resultados.length} verificaciones pasan.`);
	require('fs').writeFileSync(path.join(__dirname, 'resultado-aceptacion3.json'), JSON.stringify(resultados, null, 2));
	process.exit(f.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
