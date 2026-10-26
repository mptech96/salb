import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url), React=require('react');
function harness(fail=false){
 const states=[],calls=[];let cursor=0;
 const hooks={...React,useState(initial){const i=cursor++;if(!(i in states))states[i]=initial;return[states[i],v=>states[i]=v];}};
 const data={notice:'المتبقي غير مثبت',driver:{driver_name:'Synthetic'},costs:{data:[{id:1,shipment_number:'SHIP-1',type_name:'نقل',amount:'1000',entry_date:'2026-03-02',journal_entry_id:2,evidence_status:'A_VERIFIED_ORIGINAL',category:'C_HISTORICAL_PAYMENT_UNKNOWN',original_account:{account_code:'210',account_name:'مستحقات'}}],current_page:1,last_page:2,per_page:25,total:26},totals:{original_liabilities:'26000',total:26},reconciliation:[]};
 const api={get:async(url,options)=>{calls.push({url,options});if(fail)throw Error('HTTP 403');return{data:{data:url.endsWith('-entities')?[{id:3,driver_name:'Synthetic'}]:data}};}};
 const module={exports:{}};
 const compiled=ts.transpileModule(fs.readFileSync(new URL('../components/finance/DriverShipmentStatement.tsx',import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}}).outputText;
 new Function('require','module','exports',compiled)(n=>n==='react'?hooks:n==='react/jsx-runtime'?require(n):n==='@/app/api'?{default:api,__esModule:true}:{default:()=>null,__esModule:true},module,module.exports);
 return{calls,render(){cursor=0;return module.exports.default();}};
}
function find(node,predicate){if(!node||typeof node!=='object')return;if(Array.isArray(node)){for(const c of node){const v=find(c,predicate);if(v)return v;}return;}if(predicate(node))return node;return find(node.props?.children,predicate);}
test('read-only report preserves unknown payment and requests server pagination/search',async()=>{
 const app=harness();let tree=app.render();await find(tree,n=>n.type==='button'&&n.props.children==='تحميل السائقين ضمن نطاقي').props.onClick();await new Promise(setImmediate);
 tree=app.render();find(tree,n=>n.type==='select').props.onChange({target:{value:'3'}});
 tree=app.render();find(tree,n=>n.props?.['aria-label']==='بحث المستحقات').props.onChange({target:{value:'SHIP'}});
 tree=app.render();await find(tree,n=>n.type==='button'&&n.props.children==='عرض المستحقات').props.onClick();await new Promise(setImmediate);
 assert.equal(app.calls.at(-1).options.params.page,1);assert.equal(app.calls.at(-1).options.params.search,'SHIP');
 tree=app.render();const pagination=find(tree,n=>typeof n.props?.onPage==='function');pagination.props.onPage(2);await new Promise(setImmediate);
 assert.equal(app.calls.at(-1).options.params.page,2);
 assert.equal(find(app.render(),n=>n.type==='button'&&n.props.children==='سداد'),undefined);
 assert.match(JSON.stringify(app.render()),/المتبقي غير مثبت/);
});
test('permission failures remain visible and are not converted to empty success',async()=>{
 const app=harness(true);await find(app.render(),n=>n.type==='button'&&n.props.children==='تحميل السائقين ضمن نطاقي').props.onClick();await new Promise(setImmediate);
 assert.equal(find(app.render(),n=>n.props?.role==='alert').props.children,'HTTP 403');
});
