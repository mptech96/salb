import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const React = require('react');
const compile = (file) => ts.transpileModule(fs.readFileSync(new URL(file, import.meta.url), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
}).outputText;
const metaModule = { exports: {} };
new Function('exports', 'module', compile('../lib/expense-meta.ts'))(metaModule.exports, metaModule);

function setup({ rejectMeta = false } = {}) {
  const states = [], effects = [], requests = [];
  let index = 0;
  const accounts = Array.from({ length: 31 }, (_, i) => ({ id: i + 1, branch_id: 7, is_active: 1, account_type: i === 30 ? 'PETTY_CASH' : 'CASH', account_name: i === 30 ? 'عهد اختبار' : `Cash ${i}`, currency_code: 'SAR' }));
  accounts.push({ id: 32, branch_id: null, is_active: 1, account_type: 'OTHER', account_name: 'سلف اختبار', currency_code: 'SAR' });
  const metadata = { branches: [{ id: 7, branch_name: 'Test' }], types: [{ id: 9, type_name: 'Test expense', default_scope: 'GENERAL' }], financial_accounts: accounts, base_currency: 'SAR', scoped_branch_id: 7, shipments: [], cars: [], purchases: [], sales: [], drivers: [], workers: [], currencies: [{ currency_code: 'SAR', currency_name: 'SAR' }] };
  const api = {
    get: async (url) => {
      requests.push({ method: 'GET', url });
      if (url === '/expenses/meta') {
        if (rejectMeta) throw { response: { status: 403, data: { message: 'غير مصرح بقراءة المصروفات' } } };
        return { data: { status: true, data: metadata } };
      }
      return { data: { status: true, data: { data: [], current_page: 1, last_page: 1, per_page: 25, total: 0 }, summary: {} } };
    },
    post: async (url, payload) => { requests.push({ method: 'POST', url, payload }); return { data: { status: true } }; },
  };
  const hooks = {
    ...React,
    useState(initial) {
      const slot = index++;
      if (!(slot in states)) states[slot] = typeof initial === 'function' ? initial() : initial;
      return [states[slot], (value) => { states[slot] = typeof value === 'function' ? value(states[slot]) : value; }];
    },
    useMemo: (callback) => callback(),
    useEffect: (callback) => effects.push(callback),
  };
  const page = { exports: {} };
  new Function('require', 'exports', 'module', compile('../app/expenses/page.tsx'))((name) => {
    if (name === 'react') return hooks;
    if (name === '../api') return { __esModule: true, default: api };
    if (name === '@/lib/expense-meta') return metaModule.exports;
    if (name === 'react/jsx-runtime') return require(name);
    return { __esModule: true, default: () => null };
  }, page.exports, page);
  const render = () => { index = 0; return page.exports.default(); };
  const load = async () => {
    render();
    const cleanups = effects.splice(0).map(callback => callback());
    await new Promise(resolve => setTimeout(resolve, 350));
    cleanups.forEach(cleanup => cleanup?.());
    return render();
  };
  return { render, load, requests };
}

function find(node, predicate) {
  if (!node || typeof node !== 'object') return undefined;
  if (Array.isArray(node)) { for (const child of node) { const match = find(child, predicate); if (match) return match; } return undefined; }
  if (predicate(node)) return node;
  return find(node.props?.children, predicate);
}

test('actual expense page loads complete options and submits the unchanged expense contract', async () => {
  const app = setup();
  let tree = await app.load();
  const add = find(tree, node => node.type === 'button' && node.props.children === '+ مصروف جديد');
  assert.equal(add.props.disabled, false);
  add.props.onClick();
  tree = app.render();
  const accountSelector = find(tree, node => node.props?.endpoint === '/expenses/account-options');
  assert.equal(accountSelector.props.branch, '7');
  assert.equal(typeof accountSelector.props.onChange, 'function');
  find(tree, node => node.props?.l === 'نوع المصروف *').props.set('9');
  tree = app.render();
  find(tree, node => node.props?.endpoint === '/expenses/account-options').props.onChange('31', { id: 31, account_name: 'عهد', currency_code: 'SAR' });
  tree = app.render();
  find(tree, node => node.props?.l === 'المبلغ (SAR)').props.set('10');
  tree = app.render();
  await find(tree, node => node.type === 'button' && node.props.children === 'حفظ وترحيل المصروف').props.onClick();
  const mutations = app.requests.filter(request => request.method === 'POST');
  assert.equal(mutations.length, 1);
  assert.equal(mutations[0].url, '/expenses');
  assert.deepEqual(mutations[0].payload, { branch_id: 7, expense_type_id: 9, expense_date: new Date().toISOString().slice(0, 10), scope_type: 'GENERAL', reference_id: null, amount: 10, payment_status: 'PAID', payment_method: 'CASH', financial_account_id: 31, currency_code: 'SAR', exchange_rate: 1, foreign_amount: null, expense_effect: 'COST', notes: null });
});

test('actual expense page shows permission failure and prevents opening the mutation form', async () => {
  const app = setup({ rejectMeta: true });
  const tree = await app.load();
  assert.match(find(tree, node => node.props?.role === 'alert').props.children, /غير مصرح/);
  const add = find(tree, node => node.type === 'button' && node.props.children === '+ مصروف جديد');
  assert.equal(add.props.disabled, true);
  add.props.onClick();
  assert.equal(find(app.render(), node => node.props?.title === 'مصروف جديد'), undefined);
  assert.equal(app.requests.filter(request => request.method === 'POST').length, 0);
});
