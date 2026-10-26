import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';import ts from 'typescript';
const compiled=ts.transpileModule(readFileSync(new URL('../lib/opening-uom.ts',import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText;
const mod={exports:{}};new Function('exports','module',compiled)(mod.exports,mod);
test('opening BASE sends exact decimal text and no stale weight or client mapping',()=>{
  assert.deepEqual(mod.exports.openingInventoryPayload({uom_version:'BASE_V1',branch_id:'1',item_id:'2',quantity:'0.000001',unit_code:'M',qty_kg:'1000',base_quantity:'999',total_cost:'1.001'}),{branch_id:1,item_id:2,quantity:'0.000001',unit_code:'M',total_cost:'1.001',lot_number:null,notes:null});
});
test('legacy opening keeps the existing kilogram contract',()=>{
  assert.equal(mod.exports.openingInventoryPayload({branch_id:1,item_id:2,qty_kg:'1.125',total_cost:'10.001'}).qty_kg,1.125);
});
