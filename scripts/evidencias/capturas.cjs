// Capturas de evidencia del panel (Playwright, licencia Apache 2.0) contra la instancia de DEMOSTRACIÓN.
// Uso (ver scripts/evidencias/capturar.sh): BASE=http://wms-demo-app:8000 ADMIN_EMAIL=... ADMIN_PASSWORD=... node capturas.cjs
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://wms-demo-app:8000';
const OUT = process.env.OUT || '/out';
const DEMO_PASSWORD = 'caballo-azul-lluvia-77';
const users = {
  admin: [process.env.ADMIN_EMAIL, process.env.ADMIN_PASSWORD],
  supervisor: ['supervisor.demo@cityofertas.test', DEMO_PASSWORD],
  almacenero: ['almacenero.demo@cityofertas.test', DEMO_PASSWORD],
  compras: ['compras.demo@cityofertas.test', DEMO_PASSWORD],
  lectura: ['lectura.demo@cityofertas.test', DEMO_PASSWORD],
};
const results = [];

async function login(browser, role) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'es-PE' });
  const page = await context.newPage();
  await page.goto(`${BASE}/admin/login`);
  await page.locator('input[type="email"]').fill(users[role][0]);
  await page.locator('input[type="password"]').fill(users[role][1]);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  return { context, page };
}

async function shot(page, name, caption) {
  await page.waitForTimeout(700);
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: false });
  results.push({ file: `${name}.png`, caption });
  console.log(`ok ${name}`);
}

async function step(name, fn) {
  try { await fn(); } catch (e) { console.log(`FALLO ${name}: ${e.message.split('\n')[0]}`); }
}

async function rowAction(page, rowText, actionLabel) {
  const row = page.locator('tr', { hasText: rowText }).first();
  await row.getByRole('button', { name: actionLabel }).or(row.getByText(actionLabel)).first().click();
  await page.locator('.fi-modal-window').first().waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForTimeout(900);
}

(async () => {
  const browser = await chromium.launch();

  await step('login', async () => {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    await page.goto(`${BASE}/admin/login`);
    await shot(page, '01-login', 'Pantalla de inicio de sesión (HU-002).');
    await page.locator('input[type="email"]').fill('lectura.demo@cityofertas.test');
    await page.locator('input[type="password"]').fill('clave-incorrecta-123');
    await page.locator('button[type="submit"]').click();
    await page.waitForTimeout(1500);
    await shot(page, '02-login-fallido', 'Credenciales incorrectas: mensaje genérico, el intento se contabiliza (CA-002-3).');
    await context.close();
  });

  const { context: admin, page } = await login(browser, 'admin');
  await step('dashboard', async () => { await page.goto(`${BASE}/admin`); await page.waitForLoadState('networkidle'); await shot(page, '03-tablero', 'Tablero: valor del inventario, agotados, bajo mínimo, ajustes por aprobar, conteos y mermas (HU-046).'); });
  await step('products', async () => { await page.goto(`${BASE}/admin/products`); await shot(page, '04-productos', 'Catálogo de productos con SKU correlativo por categoría (HU-008).'); });
  await step('kardex', async () => { await page.goto(`${BASE}/admin/products`); await rowAction(page, 'Cuaderno A4', 'Kardex'); await shot(page, '05-kardex', 'Kardex del producto: stock por ubicación, movimientos y saldo corrido (HU-022).'); });
  await step('duplicate', async () => {
    await page.goto(`${BASE}/admin/products/create`);
    const name = page.getByLabel('Nombre', { exact: false }).first();
    await name.fill('cuaderno a4 100 hojas');
    await name.press('Tab');
    await page.getByText('Posible duplicado').waitFor({ timeout: 15000 });
    await shot(page, '06-aviso-duplicado', 'Aviso de posible duplicado antes de guardar (CA-013-3).');
  });
  await step('categories', async () => { await page.goto(`${BASE}/admin/categories`); await shot(page, '07-categorias', 'Categorías en tres niveles con código de prefijo (HU-015).'); });
  await step('locations', async () => { await page.goto(`${BASE}/admin/locations`); await shot(page, '08-ubicaciones', 'Ubicaciones generadas en lote, con estado congelada/activa (HU-040, HU-041).'); });
  await step('suppliers', async () => { await page.goto(`${BASE}/admin/suppliers`); await shot(page, '09-proveedores', 'Proveedores con RUC validado (HU-042, HU-043).'); });
  await step('receipts', async () => { await page.goto(`${BASE}/admin/receipts`); await shot(page, '10-recepciones', 'Recepciones: una confirmada y un borrador (HU-024 a HU-027).'); });
  await step('transfers', async () => { await page.goto(`${BASE}/admin/transfers`); await shot(page, '11-transferencias', 'Transferencia completada de BOD a PIS (HU-028).'); });
  await step('adjustments', async () => { await page.goto(`${BASE}/admin/adjustments`); await shot(page, '12-ajustes', 'Merma pendiente de aprobación por otra persona (HU-029, HU-030).'); });
  await step('counts', async () => { await page.goto(`${BASE}/admin/counts`); await shot(page, '13-conteos', 'Conteo en revisión con diferencias marcadas (HU-032 a HU-034).'); });
  await step('count-lines-admin', async () => { await page.goto(`${BASE}/admin/counts`); await rowAction(page, 'CNT', 'Ver líneas'); await shot(page, '14-conteo-supervisor', 'Líneas del conteo vistas por quien tiene counts.close: esperado y diferencia visibles.'); });
  await step('imports', async () => { await page.goto(`${BASE}/admin/imports`); await shot(page, '15-importaciones-pos', 'Importación de ventas del POS con filas en error y descarga del reporte (HU-037 a HU-039).'); });
  await step('replenishment', async () => {
    await page.goto(`${BASE}/admin/replenishment/replenishments`);
    await shot(page, '16-reposicion', 'Reposición y alertas con pedido sugerido y botón de descarga (HU-044, HU-045).');
  });
  await step('valued', async () => { await page.goto(`${BASE}/admin/reports/valued-stock/valued-stocks`); await shot(page, '17-valorizado', 'Inventario valorizado por almacén (HU-048).'); });
  await step('abc', async () => { await page.goto(`${BASE}/admin/reports/abc/abcs`); await shot(page, '18-abc', 'Rotación y clasificación ABC (HU-049).'); });
  await step('waste', async () => { await page.goto(`${BASE}/admin/reports/waste/wastes`); await shot(page, '19-mermas', 'Reporte de mermas y ajustes negativos (HU-050).'); });
  await step('audit', async () => { await page.goto(`${BASE}/admin/audit/audits`); await shot(page, '20-auditoria', 'Auditoría inmutable de acciones (HU-007).'); });
  await step('audit-detail', async () => { await page.goto(`${BASE}/admin/audit/audits`); await rowAction(page, 'Usuario', 'Ver detalle').catch(async () => { await page.getByRole('button', { name: 'Ver detalle' }).first().click(); await page.waitForTimeout(1200); }); await shot(page, '21-auditoria-detalle', 'Detalle de auditoría con valores antes y después (CA-007-1).'); });
  await step('users', async () => { await page.goto(`${BASE}/admin/users`); await shot(page, '22-usuarios', 'Usuarios y roles (HU-001, HU-003, HU-004).'); });
  await step('profile', async () => { await page.goto(`${BASE}/admin/profile`); await page.waitForLoadState('networkidle'); await shot(page, '23-perfil-2fa', 'Mi perfil: configuración de la verificación en dos pasos (HU-006).'); });
  await admin.close();

  await step('almacenero', async () => {
    const { context, page: p } = await login(browser, 'almacenero');
    await p.goto(`${BASE}/admin/counts`);
    await rowAction(p, 'CNT', 'Ver líneas');
    await shot(p, '24-conteo-ciego', 'Conteo ciego: el almacenero no ve el esperado ni la diferencia (CA-033-1).');
    const r = await p.goto(`${BASE}/admin/products/create`);
    await shot(p, '25-almacenero-sin-permiso', `El almacenero no puede crear productos: respuesta HTTP ${r ? r.status() : '?'} (CA-008-3).`);
    await context.close();
  });
  await step('lectura', async () => {
    const { context, page: p } = await login(browser, 'lectura');
    await p.goto(`${BASE}/admin/replenishment/replenishments`);
    await shot(p, '26-lectura-reposicion', 'Rol lectura: ve la reposición pero no tiene el botón de descarga (CA-045-4, defecto D03 corregido).');
    await p.goto(`${BASE}/admin/reports/valued-stock/valued-stocks`);
    await shot(p, '27-lectura-reporte', 'Rol lectura: ve el reporte valorizado sin exportación (CA-051-2).');
    const r = await p.goto(`${BASE}/admin/audit/audits`);
    await shot(p, '28-lectura-auditoria', `Rol lectura sin audit.view: respuesta HTTP ${r ? r.status() : '?'} (CA-007-2).`);
    await context.close();
  });
  await step('compras', async () => {
    const { context, page: p } = await login(browser, 'compras');
    await p.goto(`${BASE}/admin/replenishment/replenishments`);
    await shot(p, '29-compras-reposicion', 'Rol compras: pedido sugerido con descarga habilitada (CA-045-1).');
    await context.close();
  });

  await browser.close();
  require('fs').writeFileSync(`${OUT}/capturas.json`, JSON.stringify(results, null, 2));
})();
