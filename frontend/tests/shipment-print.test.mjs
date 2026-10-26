import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import ts from 'typescript';
function load(path, dependencies={}) {
  const js=ts.transpileModule(readFileSync(new URL(path,import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText;
  const mod={exports:{}};new Function('exports','module','require',js)(mod.exports,mod,name=>dependencies[name]);return mod.exports;
}
const inventory=load('../lib/inventory-uom.ts');
const {shipmentPrintLine}=load('../lib/shipment-print.ts',{'./inventory-uom':inventory});
test('shipment printing preserves captured counted units and unknown physical weight',()=>{
  const row={uom_version:'BASE_V1',quantity:'0.250000',unit_code:'CARTON',base_quantity:'3.000000',base_unit_code_snapshot:'PCS',actual_weight_kg:null,gross_qty_kg:'0.000',uom_price_snapshot:'120.123456'};
  assert.deepEqual(shipmentPrintLine(row),{quantity:'0.25 CARTON',inventoryQuantity:'3 PCS',physicalWeight:'غير موثق',deduction:'—',priceUnit:'CARTON',price:'120.123456'});
  assert.equal(shipmentPrintLine({...row,actual_weight_kg:'48.125'}).physicalWeight,'48.125 KG');
});
test('shipment printing retains tiny inventory quantities and legacy TON pricing',()=>{
  assert.equal(shipmentPrintLine({uom_version:'BASE_V1',quantity:'0.000001',unit_code:'M',base_quantity:'0.000001',base_unit_code_snapshot:'M'}).quantity,'0.000001 M');
  const old=shipmentPrintLine({accepted_qty_kg:'999.875',gross_qty_kg:'1000.000',deduction_qty_kg:'0.125',price_unit:'TON',unit_price_per_kg:'0.250125'});
  assert.equal(old.quantity,'999.875 KG');assert.equal(old.physicalWeight,'1,000 KG');assert.equal(old.price,250.125);
});
