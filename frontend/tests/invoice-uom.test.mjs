import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const compiled = ts.transpileModule(readFileSync(new URL('../lib/invoice-uom.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
const module = { exports: {} };
new Function('exports', 'module', compiled)(module.exports, module);
const { operationalInvoiceLine, storedInvoiceLine, lineQuantityText, lineUnitPrice } = module.exports;

test('operational invoice payload retains six-decimal strings and omits client base calculations', () => {
  const input = { item_id: '7', quantity: '0.123456', unit_code: 'CARTON', unit_price: '123.456789', qty_kg: '999', base_quantity: '999', unit_factor_to_base: '999', discount_amount: '0', vat_percent: '' };
  const output = operationalInvoiceLine(input, { inventory_quantity_version: 'BASE_V1' });
  assert.equal(output.quantity, '0.123456');
  assert.equal(output.unit_price, '123.456789');
  assert.equal(output.unit_code, 'CARTON');
  assert.equal(output.price_unit, 'UNIT');
  for (const key of ['qty_kg', 'base_quantity', 'unit_factor_to_base']) assert.equal(Object.hasOwn(output, key), false);
  assert.equal(input.qty_kg, '999');
});

test('reopened and displayed BASE lines use captured quantity, unit, factor and price', () => {
  const captured = { item_id: 7, uom_version: 'BASE_V1', quantity: '0.500000', base_quantity: '6.000000', unit_code: 'CARTON', uom_factor_snapshot: '12.000000', uom_price_snapshot: '180.000001', qty_kg: '0.000', unit_price_per_kg: '0.000000' };
  const reopened = storedInvoiceLine(captured);
  assert.equal(reopened.quantity, '0.500000');
  assert.equal(reopened.unit_price, '180.000001');
  assert.equal(reopened.uom_factor_snapshot, '12.000000');
  assert.equal(lineQuantityText(captured), '0.500000 CARTON');
  assert.equal(lineUnitPrice(captured), '180.000001');
});

test('legacy kilograms, ton pricing and service payloads remain compatible', () => {
  const legacy = operationalInvoiceLine({ item_id: '2', qty_kg: '2500', price_unit: 'TON', unit_price: '1500', vat_percent: '' }, { item_type: 'STOCK' });
  assert.equal(legacy.qty_kg, 2500); assert.equal(legacy.price_unit, 'TON'); assert.equal(legacy.quantity, null);
  const service = operationalInvoiceLine({ item_id: '3', quantity: '2.5', unit_code: 'HOUR', unit_price: '100', vat_percent: '' }, { item_type: 'SERVICE' });
  assert.equal(service.quantity, 2.5); assert.equal(service.qty_kg, null); assert.equal(service.unit_code, 'HOUR');
  assert.equal(lineQuantityText({ qty_kg: '2500.000' }), '2500.000 كجم');
});
