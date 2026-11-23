// Valorización automática por comparables. Se ejecuta después de las suites 1 a 4.
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = 'http://localhost:8080';
const CAP = path.join(__dirname, 'capturas');
const resultados = [];
const db = (...a) => JSON.parse(execFileSync('php', [path.join(__dirname, 'consulta.php'), ...a], { encoding: 'utf8' }));
const ok = (n, c, d = '') => { resultados.push({ nombre: n, ok: !!c, detalle: d }); console.log(`${c ? 'PASA ' : 'FALLA'}  ${n}${d ? ' — ' + d : ''}`); };
const pesos = (t) => t.match(/\$[\d.]+/g).map((x) => Number(x.replace(/[$.]/g, '')));
const ufs = (t) => t.match(/UF [\d.]+/g).map((x) => Number(x.replace(/[UF .]/g, '')));

async function login(browser, u, p) {
	const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
	const pg = await ctx.newPage();
	await pg.goto(`${BASE}/wp-login.php`);
	await pg.fill('#user_login', u);
	await pg.fill('#user_pass', p);
	await Promise.all([pg.waitForNavigation(), pg.click('#wp-submit')]);
	return pg;
}
const enviar = (pg, sel) => Promise.all([pg.waitForNavigation(), pg.locator(sel).first().click()]);
const esperaHumana = (p) => p.waitForTimeout(2100);

async function pedirValorizacion(m, datos) {
	await m.goto(`${BASE}/publica-tu-propiedad/?quiero=valorizar`);
	await m.selectOption('#propietario-tipo', datos.tipo);
	await m.selectOption('#propietario-comuna_id', { label: datos.comuna });
	if (datos.dorm) { await m.fill('#propietario-dormitorios', datos.dorm); }
	if (datos.sup) { await m.fill('#propietario-superficie', datos.sup); }
	await m.fill('#propietario-nombre', datos.nombre);
	await m.fill('#propietario-email', datos.email);
	await m.fill('#propietario-telefono', '+56 9 4444 0505');
	await m.check('#propietario-consentimiento');
	await esperaHumana(m);
	await enviar(m, '#inmo-propietario button[type=submit]');
	return m.textContent('#inmo-propietario');
}

(async () => {
	db('reset_limite'); // Límite anti-spam por IP: las suites anteriores ya enviaron formularios desde la misma IP.
	const browser = await chromium.launch();
	const cam = await login(browser, 'camila.rojas', 'Prueba.Corredor.2026');

	/* 8.1 Importar comparables de mercado */
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-valorizacion`);
	await cam.setInputFiles('#v-csv', path.join(__dirname, 'datos', 'comparables-prueba.csv'));
	await enviar(cam, 'form:has(#v-csv) button');
	const aviso = (await cam.locator('.notice').allTextContents()).join(' ');
	ok('8.1 Importa el CSV y reporta las filas inválidas con su número', /13 comparable\(s\) importado/.test(aviso) && /Fila 15: .*comuna/.test(aviso) && /Fila 16: .*moneda/.test(aviso), aviso.replace(/\s+/g, ' ').slice(0, 160));

	/* 8.2 Propietario pide valorización desde el celular */
	const movil = await browser.newContext({ ...devices['Pixel 7'] });
	const m = await movil.newPage();
	const t1 = await pedirValorizacion(m, { tipo: 'departamento', comuna: 'Ñuñoa', dorm: '2', sup: '60', nombre: 'Rodrigo Valdés (ficticio)', email: 'rodrigo.valdes@example.test' });
	await m.screenshot({ path: `${CAP}/m-30-valorizacion.png`, fullPage: false });
	const est = await m.locator('#inmo-estimacion').count() ? await m.textContent('#inmo-estimacion') : '';
	const rango = est ? pesos(est) : [];
	ok('8.2 Ve un rango de arriendo estimado para 60 m² en Ñuñoa (≈ $10.000/m²)', /Arriendo mensual estimado/.test(est) && rango.length >= 2 && rango[0] <= 600000 && rango[1] >= 600000 && rango[0] >= 540000 && rango[1] <= 660000, rango.join(' – '));
	ok('8.3 Sin comparables de venta suficientes no inventa un valor de venta', !/Valor de venta estimado/.test(est));
	ok('8.4 Se explica que es referencial y no una tasación', /no constituye una tasación/.test(est) && /comparables en Ñuñoa/.test(est));
	const vz = db('valorizaciones_consulta');
	ok('8.5 La estimación queda registrada para el corredor (venta y arriendo)', vz.length === 2 && vz.some((x) => x.operacion === 'arriendo' && x.valor) && vz.some((x) => x.operacion === 'venta' && !x.valor));
	// Las valorizaciones web se asignan al corredor por defecto (administración).
	const adm = await login(browser, 'admin', 'Admin.Demo.2026');
	await adm.goto(`${BASE}/wp-admin/admin.php?page=inmo-consultas&tipo=valorizacion`);
	await adm.screenshot({ path: `${CAP}/e5-03-bandeja.png`, fullPage: false });
	ok('8.6 La bandeja muestra la estimación junto a la solicitud', /Arriendo: \$[\d.]+ – \$[\d.]+/.test(await adm.textContent('.inmo-wrap')));

	/* 8.7 Casos sin estimación */
	const t2 = await pedirValorizacion(m, { tipo: 'departamento', comuna: 'Ñuñoa', nombre: 'Rodrigo Valdés (ficticio)', email: 'rodrigo.valdes@example.test' });
	ok('8.7 Sin superficie: no hay rango y se pide la superficie', !/estimado/.test(t2) && /indicas la superficie/.test(t2));
	const t3 = await pedirValorizacion(m, { tipo: 'local', comuna: 'Tiltil', sup: '100', nombre: 'Rodrigo Valdés (ficticio)', email: 'rodrigo.valdes@example.test' });
	ok('8.8 Sin datos suficientes (local en Tiltil): no hay rango, contacto del corredor', !/estimado/.test(t3) && /Un corredor te contactará/.test(t3));

	/* 8.9 Calculadora: casa en venta en La Reina */
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-valorizacion&tipo=casa&operacion=venta&comuna_id=&superficie=170`);
	await cam.selectOption('#v-com', { label: 'La Reina' });
	await cam.fill('#v-superficie', '170');
	await enviar(cam, '.inmo-val-form button');
	const res = await cam.textContent('#inmo-val-resultado');
	const r9 = ufs(res.split('Valor central')[0]);
	await cam.screenshot({ path: `${CAP}/e5-01-calculadora.png`, fullPage: true });
	ok('8.9 Casa de 170 m² en La Reina: rango coherente con ≈ UF 29–31/m²', r9.length >= 2 && r9[0] >= 4600 && r9[1] <= 5500 && r9[0] < r9[1], r9.join(' – '));
	ok('8.10 Usa el cierre propio (CP-0001 vendida) además del mercado', /CP-0001/.test(res) && /Cierre propio/.test(res));

	/* 8.11 Antigüedad: el dato de 2023 se excluye */
	await cam.goto(`${BASE}/wp-admin/admin.php?page=inmo-valorizacion&tipo=departamento&operacion=arriendo&superficie=60`);
	await cam.selectOption('#v-com', { label: 'Ñuñoa' });
	await enviar(cam, '.inmo-val-form button');
	const res2 = await cam.textContent('#inmo-val-resultado');
	ok('8.11 Los comparables de más de 24 meses no se usan', !/10-01-2023/.test(res2) && /comparables de departamento en arriendo en Ñuñoa/.test(res2));

	/* 8.12 Ficha interna: Providencia (sin comparables en la comuna → región) */
	const p2 = db('propiedad', 'CP-0002');
	await cam.goto(`${BASE}/wp-admin/post.php?post=${p2.id}&action=edit`);
	const mb = await cam.textContent('#inmo_valorizacion');
	await cam.screenshot({ path: `${CAP}/e5-02-ficha.png`, fullPage: false });
	ok('8.12 La ficha muestra rango, confianza y la comparación con el precio publicado', /\$[\d.]+ – \$[\d.]+/.test(mb) && /región/.test(mb) && /(dentro|sobre|bajo) del rango|dentro del rango|sobre el rango|bajo el rango/.test(mb), mb.replace(/\s+/g, ' ').slice(0, 150));

	/* 8.13 Mostrar el rango al público se puede desactivar */
	db('ajuste', 'valorizacion_publica', '0');
	const t4 = await pedirValorizacion(m, { tipo: 'departamento', comuna: 'Ñuñoa', dorm: '2', sup: '60', nombre: 'Rodrigo Valdés (ficticio)', email: 'rodrigo.valdes@example.test' });
	ok('8.13 Con la opción desactivada el propietario no ve el rango, pero el corredor sí', !/estimado/.test(t4) && db('valorizaciones_consulta').some((x) => x.operacion === 'arriendo' && x.valor));
	db('ajuste', 'valorizacion_publica', '1');

	await browser.close();
	const f = resultados.filter((x) => !x.ok);
	console.log(`\n${resultados.length - f.length}/${resultados.length} verificaciones pasan.`);
	require('fs').writeFileSync(path.join(__dirname, 'resultado-aceptacion5.json'), JSON.stringify(resultados, null, 2));
	process.exit(f.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
