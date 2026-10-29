// Evidencia del flujo completo de ventas del POS desde la pantalla: subir CSV, confirmar y descargar errores.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE || 'http://wms-demo-app:8000';
const OUT = process.env.OUT || '/out';

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
  const page = await context.newPage();
  await page.goto(`${BASE}/admin/login`);
  await page.locator('input[type="email"]').fill('supervisor.demo@cityofertas.test');
  await page.locator('input[type="password"]').fill('caballo-azul-lluvia-77');
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => !u.pathname.endsWith('/login'));

  const csv = '/tmp/ventas-caja-29-10.csv';
  fs.writeFileSync(csv, 'sku,cantidad\nLIB-000001,1\nNO-EXISTE,1\nLIB-000004,abc\nJUG-000001,1\n');

  await page.goto(`${BASE}/admin/imports`);
  await page.getByRole('button', { name: 'Confirmar importación' }).first().click();
  const modal = page.locator('.fi-modal-window').first();
  await modal.waitFor({ state: 'visible' });
  const [chooser] = await Promise.all([page.waitForEvent('filechooser'), modal.getByText('Examina').click()]);
  await chooser.setFiles(csv);
  await modal.locator('.filepond--file-info-main', { hasText: 'ventas-caja-29-10.csv' }).waitFor({ timeout: 15000 });
  await page.waitForTimeout(3500); // FilePond termina de subir
  await page.screenshot({ path: `${OUT}/30-pos-subir-archivo.png` });
  console.log('ok 30-pos-subir-archivo');
  await modal.locator('.fi-modal-footer button').filter({ hasText: /Enviar|Confirmar/ }).first().click();
  await page.getByText(/Importación confirmada|No se pudo importar/).first().waitFor({ timeout: 30000 });
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${OUT}/31-pos-resultado.png` });
  console.log('ok 31-pos-resultado');

  await page.goto(`${BASE}/admin/imports`);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: `${OUT}/15-importaciones-pos.png` });
  console.log('ok 15-importaciones-pos');
  const [download] = await Promise.all([
    page.waitForEvent('download', { timeout: 20000 }),
    page.locator('tr', { hasText: 'ventas-caja-29-10.csv' }).getByText('Descargar errores').click(),
  ]);
  await download.saveAs(`${OUT}/errores-ventas-descargado.csv`);
  console.log('ok descarga ' + download.suggestedFilename());
  await browser.close();
})();
