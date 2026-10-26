import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const source=readFileSync(new URL('../components/finance/AccountSearchSelect.tsx',import.meta.url),'utf8');
const compiled=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}}).outputText;
const compiledModule={exports:{}};
new Function('require','exports','module',compiled)(()=>({}),compiledModule.exports,compiledModule);
const normalize=compiledModule.exports.accountOptionPage;

test('remote options preserve operational and GL identities separately',()=>{
  const account={id:1099,gl_account_id:42,account_name:'عهدة عبدالسلام'};
  const result=normalize({status:true,data:{data:[account],last_page:44}});
  assert.equal(result.data[0].id,1099);
  assert.equal(result.data[0].gl_account_id,42);
  assert.equal(result.last_page,44);
  assert.equal(normalize({status:true,data:{data:[{id:42,account_name:'GL'}],last_page:1}}).data[0].id,42);
});
test('API permission and malformed responses are visible errors, not empty lists',()=>{
  assert.throws(()=>normalize({status:false,message:'لا تملك الصلاحية'}),/الصلاحية/);
  assert.throws(()=>normalize({status:true,data:{data:{}}}),/غير صالحة/);
  assert.throws(()=>normalize({status:true,data:{data:[null]}}),/غير صالحة/);
});
test('remote selector debounces, cancels old requests and resolves selected ID separately',()=>{
  assert.match(source,/setTimeout/);
  assert.match(source,/abort\.abort\(\)/);
  assert.match(source,/selected_id: value/);
  assert.match(source,/page, per_page: 25/);
});
