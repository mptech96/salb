import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import ts from 'typescript';
const source=ts.transpileModule(readFileSync(new URL('../lib/commercial-uom.ts',import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText;
const mod={exports:{}};new Function('exports','module',source)(mod.exports,mod);const payload=mod.exports.commercialLinePayload;
test('commercial BASE payload retains decimal text and removes historical server snapshots and fake weights',()=>{
  const actual=payload({item_id:1,quantity:'0.000001',unit_code:'M',unit_price:'999999999999.123456',price_unit:'TON',qty_kg:1000,base_quantity:'24',uom_mapping_id:99,uom_factor_snapshot:'24',_captured_line_id:9},{inventory_quantity_version:'BASE_V1'});
  assert.deepEqual(actual,{item_id:1,quantity:'0.000001',unit_code:'M',unit_price:'999999999999.123456',price_unit:'UNIT'});
});
test('legacy TON and KG commercial payload contracts remain unchanged',()=>{
  assert.equal(payload({quantity:'0.25',unit_code:'TON'}).qty_kg,250);
  assert.equal(payload({quantity:'0.25',unit_code:'KG'}).qty_kg,0.25);
});
test('support controls require the exact action capability and WRITE access',()=>{
  const user={is_support_mode:true,support_access_level:'WRITE',support_capabilities:['POST:api/purchase-orders/{id}/convert-to-invoice']};
  assert.equal(mod.exports.commercialSupportAction(user,'/purchase-orders','convert'),true);
  assert.equal(mod.exports.commercialSupportAction(user,'/quotations','convert'),false);
  assert.equal(mod.exports.commercialSupportAction(user,'/purchase-orders','create'),false);
  assert.equal(mod.exports.commercialSupportAction({...user,support_access_level:'READ_ONLY'},'/purchase-orders','convert'),false);
});
