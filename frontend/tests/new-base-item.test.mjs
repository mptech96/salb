import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import ts from 'typescript';
const require=createRequire(import.meta.url);
const code=ts.transpileModule(readFileSync(new URL('../components/inventory/NewBaseItem.tsx',import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}}).outputText;
function component(states,api){let index=0;const module={exports:{}};new Function('require','exports','module',code)(name=>name==='react'?{useEffect(){},useState(initial){return [index<states.length?states[index++]:(index++,initial),()=>{}];}}:name==='@/app/api'?{default:api}:require(name),module.exports,module);return module.exports.default;}
test('new BASE item form remains absent without approved schema metadata',()=>{assert.equal(component([],{} )({}),null);});
test('new BASE item creation sends catalog identity only, never legacy KG or client factor',async()=>{
 const calls=[];let created=0;const C=component([[{id:3,code:'M',name_ar:'متر'}],' QA length ','QA-M','3',false,''],{async post(...args){calls.push(args);}});
 const tree=C({onCreated(){created++;}});tree.props.onSubmit({preventDefault(){}});await new Promise(resolve=>setImmediate(resolve));
 assert.deepEqual(calls,[['/items',{item_name:'QA length',item_code:'QA-M',item_type:'STOCK',track_inventory:true,base_uom_id:3}]]);assert.equal(created,1);
});
