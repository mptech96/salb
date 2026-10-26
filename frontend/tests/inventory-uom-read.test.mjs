import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

const require = createRequire(import.meta.url);
function load(relative) {
  const source = readFileSync(new URL(relative, import.meta.url), 'utf8');
  const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText;
  const output = { exports: {} };
  new Function('require', 'exports', 'module', compiled)(name => {
    if (name.startsWith('@/lib/')) return load('../lib/' + name.slice('@/lib/'.length) + '.ts');
    if (name.startsWith('@/components/')) return { default: () => null };
    return require(name);
  }, output.exports, output);
  return output.exports;
}
const quantities = load('../lib/inventory-uom.ts');

test('transfer payload sends exact selected quantity and no stale weight or client conversion', () => {
  const row = { item_id: '1', quantity: '0.000001', unit_code: 'M', qty_kg: '900', base_quantity: '999', uom_factor_snapshot: '999' };
  assert.deepEqual(quantities.transferLinePayload(row, { inventory_quantity_version: 'BASE_V1' }), { item_id: 1, notes: null, quantity: '0.000001', unit_code: 'M' });
  assert.deepEqual(quantities.transferLinePayload({ item_id: '2', qty_kg: '12.5', quantity: '999' }, {}), { item_id: 2, notes: null, qty_kg: 12.5 });
  assert.throws(() => quantities.transferLinePayload({ ...row, quantity: '1e-6' }, { inventory_quantity_version: 'BASE_V1' }));
  assert.throws(() => quantities.transferLinePayload({ ...row, unit_code: '' }, { inventory_quantity_version: 'BASE_V1' }));
});

test('inventory display preserves tiny, negative and large decimal quantities exactly', () => {
  assert.equal(quantities.exactQuantity('0.000001'), '0.000001');
  assert.equal(quantities.exactQuantity('-0.500000'), '-0.5');
  assert.equal(quantities.exactQuantity('123456789012345678.123456'), '123,456,789,012,345,678.123456');
  assert.equal(quantities.exactQuantity('1e-7'), '—');
  assert.equal(quantities.inventoryBalance({ balance_base_quantity: '12.000000', balance_kg: 0, base_unit_code: 'PCS' }), '12 PCS');
  assert.equal(quantities.movementQuantity({ uom_version: 'BASE_V1', base_quantity: '0.000001', base_unit_code: 'M', qty_kg: 0 }), '0.000001 M');
  assert.equal(quantities.lotQuantity({ uom_version: 'BASE_V1', remaining_base_qty: '5.000000', base_unit_code: 'PCS' }, 'remaining'), '5 PCS');
});

test('invoice table renders saved six-place BASE quantity and price, not fake kilograms', () => {
  const Invoice = load('../components/print/TaxInvoicePrint.tsx').default;
  const html = renderToStaticMarkup(Invoice({ kind: 'purchase', data: { invoice: { invoice_number: 'QA-UOM', document_type: 'TAX_INVOICE' }, lines: [{ id: 1, item_code: 'QA-M', item_name: 'QA length', uom_version: 'BASE_V1', quantity: '0.000001', unit_code: 'M', uom_price_snapshot: '1.234567', unit_price_per_kg: 0, qty_kg: 0 }] }, profile: { print_options: { header_mode: 'TEXT' } } }));
  assert.match(html, /<td>0\.000001<\/td>/);
  assert.match(html, /<td>1\.234567<\/td>/);
  assert.match(html, /<td>M<\/td>/);
});
