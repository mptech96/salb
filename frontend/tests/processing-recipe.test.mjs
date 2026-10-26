import test from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';import ts from 'typescript';
const js=ts.transpileModule(readFileSync(new URL('../lib/processing-recipe.ts',import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText;const mod={exports:{}};new Function('exports','module',js)(mod.exports,mod);
test('recipe editor supports legacy KG and BASE separately and excludes non-stock items',()=>{
 const old={id:1,inventory_quantity_version:'LEGACY_KG'},base={id:2,inventory_quantity_version:'BASE_V1',uom_units:[{id:9,code:'PCS'}]};
 assert.deepEqual(mod.exports.recipeItems([old,base,{id:3,item_type:'SERVICE'},{id:4,track_inventory:0}]),[old,base]);
 assert.deepEqual(mod.exports.recipeUnits(old).map(x=>x.code),['KG','TON']);assert.deepEqual(mod.exports.recipeUnits(base).map(x=>x.code),['PCS']);assert.deepEqual(mod.exports.recipeUnits(),[]);
});
