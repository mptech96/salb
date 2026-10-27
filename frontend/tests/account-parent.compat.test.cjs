const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const Module = require('node:module');
const ts = require('typescript');
const React = require('react');
const {renderToStaticMarkup} = require('react-dom/server');
const root = path.resolve(__dirname,'..');
const resolve = Module._resolveFilename;
Module._resolveFilename = function(name,...args){return resolve.call(this,name.startsWith('@/')?path.join(root,name.slice(2)):name,...args);};
require.extensions['.tsx'] = function(module, filename){const compiled=ts.transpileModule(fs.readFileSync(filename,'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}});module._compile(compiled.outputText,filename);};
const Form=require('../app/accounts/AccountForm.tsx').default;
test('parent selector includes active groups, excludes inactive/analytical rows',()=>{
 const html=renderToStaticMarkup(React.createElement(Form,{form:{parent_id:''},setForm:()=>{},onSave:()=>{},onCancel:()=>{},accounts:[{id:1,account_name:'ACTIVE_GROUP_QA',is_group:1,is_active:1},{id:2,account_name:'INACTIVE_GROUP_QA',is_group:1,is_active:0},{id:3,account_name:'LEAF_QA',is_group:0,is_active:1}]}));
 assert.ok(html.includes('ACTIVE_GROUP_QA'));assert.ok(!html.includes('INACTIVE_GROUP_QA'));assert.ok(!html.includes('LEAF_QA'));
});
test('string flags remain compatible with existing API serialization',()=>{
 const html=renderToStaticMarkup(React.createElement(Form,{form:{parent_id:''},setForm:()=>{},onSave:()=>{},onCancel:()=>{},accounts:[{id:1,account_name:'STRING_ACTIVE_QA',is_group:'1',is_active:'1'},{id:2,account_name:'STRING_INACTIVE_QA',is_group:'1',is_active:'0'}]}));assert.ok(html.includes('STRING_ACTIVE_QA'));assert.ok(!html.includes('STRING_INACTIVE_QA'));
});
