import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import ts from 'typescript';
const compiled=ts.transpileModule(readFileSync(new URL('../lib/inventory-uom.ts',import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText;
const module={exports:{}};new Function('exports','module',compiled)(module.exports,module);const q=module.exports;
test('BASE return and operation displays preserve captured units and six-place quantities',()=>{
  const row={id:1,uom_version:'BASE_V1',quantity:'0.000001',return_quantity:'0.000001',unit_code:'M',qty_kg:'0.000',track_inventory_snapshot:1};
  assert.equal(q.returnLineQuantity(row),'0.000001');assert.equal(q.returnLineUnit(row),'M');
  assert.deepEqual(q.returnPayloadLine(row),{source_invoice_line_id:1,quantity:'0.000001',notes:null});
  assert.equal(q.operationLineQuantity(row),'0.000001 M');
  assert.equal(q.returnLineQuantity({qty_kg:'12.000',quantity:'0',track_inventory_snapshot:1}),'12');
  assert.equal(q.returnLineUnit({qty_kg:'12.000',track_inventory_snapshot:1}),'KG');
});
test('dashboard keeps dimensions separate instead of displaying fake zero stock',()=>{
  assert.equal(q.dashboardStockText({stock_qty:0,stock_quantities_by_unit:[{base_unit_code:'PCS',base_quantity:'7.000000'},{base_unit_code:'M',base_quantity:'0.000001'}]}),'7 PCS / 0.000001 M');
  assert.equal(q.dashboardStockText({stock_qty:'12.500000'}),'12.5');
});
