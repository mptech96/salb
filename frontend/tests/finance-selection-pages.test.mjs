import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const React = require('react');
const flush = () => new Promise(resolve => setImmediate(resolve));
const empty = () => null;

test('shipment editor preserves stored line IDs without submitting server-owned references', async () => {
  const app = setup('../app/shipments/page.tsx', { base_currency: 'SAR', branches: [], items: [], currencies: [] });
  await app.load();
  app.hydrate(value => value === null, () => ({ shipment: { id: 14, branch_id: 7, shipment_type: 'PURCHASE', commercial_status: 'DRAFT', shipment_number: 'TEST', shipment_date: '2026-03-05', currency_code: 'SAR' },
    items: [{ id: 81, item_id: 1, allocation_line_ref: 'BL:SERVER-A', gross_qty_kg: 5, unit_price_per_kg: 10 }, { id: 82, item_id: 1, allocation_line_ref: 'BL:SERVER-B', gross_qty_kg: 5, unit_price_per_kg: 10 }], costs: [] }));
  let tree = app.render();
  find(tree, n => Array.isArray(n.props?.children) && n.props.children[0] === 'تجهيز / تسعير الأصناف ').props.onClick();
  tree = app.render();
  await find(tree, n => n.props?.children === 'حفظ المسودة').props.onClick();
  assert.equal(app.requests[0].url, '/shipments/14');
  assert.deepEqual(app.requests[0].payload.items.map(x => x.id), [81, 82]);
  assert.ok(app.requests[0].payload.items.every(x => !Object.hasOwn(x, 'allocation_line_ref')));
});

function setup(file, metadata, rows = []) {
  const states = [], effects = [], requests = [];
  let cursor = 0;
  const hooks = { ...React, useMemo: callback => callback(), useEffect: callback => effects.push(callback),
    useState(initial) { const slot = cursor++; if (!(slot in states)) states[slot] = typeof initial === 'function' ? initial() : initial;
      return [states[slot], value => { states[slot] = typeof value === 'function' ? value(states[slot]) : value; }]; },
  };
  const api = {
    get: async url => ({ data: { status: true, data: url.endsWith('/meta') || url.endsWith('-meta') || url === '/financial-setup' ? metadata : file.includes('financial-accounts') ? { data: rows, total: rows.length, current_page: 1, last_page: 1 } : rows } }),
    post: async (url, payload) => { requests.push({ url, payload }); return { data: { status: true, id: 99 } }; },
    put: async (url, payload) => { requests.push({ url, payload }); return { data: { status: true } }; },
  };
  const compiled = ts.transpileModule(fs.readFileSync(new URL(file, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
  }).outputText;
  const page = { exports: {} };
  new Function('require', 'module', 'exports', compiled)(name => {
    if (name === 'react') return hooks;
    if (name === 'react/jsx-runtime') return require(name);
    if (name === '@/lib/shipment-uom') {const source=ts.transpileModule(fs.readFileSync(new URL('../lib/shipment-uom.ts',import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText;const helper={exports:{}};new Function('exports','module',source)(helper.exports,helper);return helper.exports;}
    if (name === '../api') return { __esModule: true, default: api };
    if (name === '@/lib/session') return { readSession: () => ({ user: { role: { role_code: 'COMPANY_OWNER' } } }) };
    if (name.endsWith('useSystemFeedback')) return { __esModule: true, default: () => ({ feedbackDialog: null, requestConfirmation: empty }) };
    if (name.endsWith('ListUX')) return new Proxy({ usePagedSearch: data => ({ paged: data, page: 1, pageSize: 25, query: '', total: data.length, totalPages: 1, setPage: empty, setPageSize: empty, setQuery: empty }) }, { get: (target, key) => target[key] || empty });
    return new Proxy({ __esModule: true, default: empty }, { get: (target, key) => target[key] || empty });
  }, page, page.exports);
  const render = () => { cursor = 0; return page.exports.default(); };
  const load = async () => { render(); effects.splice(0).forEach(callback => callback()); await flush(); return render(); };
  return { render, load, requests, hydrate: (predicate, update) => { const slot = states.findIndex(predicate); assert.notEqual(slot, -1); states[slot] = update(states[slot]); } };
}

function find(node, predicate) {
  if (!node || typeof node !== 'object') return;
  if (Array.isArray(node)) { for (const child of node) { const result = find(child, predicate); if (result) return result; } return; }
  if (predicate(node)) return node;
  for (const key of ['children', 'actions', 'footer']) { const result = find(node.props?.[key], predicate); if (result) return result; }
}

test('tax form restores saved GL IDs and sends changed raw GL IDs to the existing API', async () => {
  const app = setup('../app/financial-setup/page.tsx', { settings: {}, currencies: [], branches: [], cost_centers: [], posting_accounts: [], readiness: {}, tax_codes: [{ id: 7, tax_code: 'TEST', tax_name: 'Test', tax_type: 'VAT', rate: 15, sales_tax_account_id: 8101, purchase_tax_account_id: 8102, is_active: 1 }] });
  let tree = await app.load();
  find(tree, n => n.type === 'button' && n.props.children === 'تعديل').props.onClick();
  tree = app.render();
  assert.equal(find(tree, n => n.props?.endpoint === '/financial-setup/gl-options' && n.props.label === 'حساب ضريبة المخرجات').props.value, '8101');
  find(tree, n => n.props?.endpoint === '/financial-setup/gl-options' && n.props.label === 'حساب ضريبة المخرجات').props.onChange('9999');
  tree = app.render();
  const panel = find(tree, n => n.props?.title === 'أكواد الضرائب');
  await find(panel, n => typeof n.props?.onClick === 'function' && 'saving' in n.props).props.onClick();
  assert.equal(app.requests[0].url, '/financial-setup/tax-code');
  assert.equal(app.requests[0].payload.sales_tax_account_id, 9999);
  assert.equal(app.requests[0].payload.purchase_tax_account_id, 8102);
});

test('financial account edit preserves operational ID and submits GL identity without substitution', async () => {
  const app = setup('../app/financial-accounts/page.tsx', { branches: [], accounts: [], currencies: [{ currency_code: 'SAR', is_base: 1 }] }, [{ id: 31, branch_id: null, account_name: 'TEST custody', account_code: 'TEST', account_type: 'PETTY_CASH', gl_account_id: 8101, currency_code: 'SAR', is_active: 1 }]);
  let tree = await app.load();
  find(tree, n => n.type === 'button' && n.props.children === 'تعديل').props.onClick();
  tree = app.render();
  const selector = find(tree, n => n.props?.endpoint === '/financial-accounts/gl-options?account_type=ASSET');
  assert.equal(selector.props.value, '8101');
  selector.props.onChange('9999');
  tree = app.render();
  find(tree, n => n.type === 'button' && n.props.children === 'حفظ').props.onClick();
  await flush();
  assert.equal(app.requests[0].url, '/financial-accounts/31');
  assert.equal(app.requests[0].payload.gl_account_id, 9999);
  assert.equal(app.requests[0].payload.account_type, 'PETTY_CASH');
});

test('item edit keeps existing account mapping and sends selected GL ID to item update', async () => {
  const app = setup('../app/items/page.tsx', { groups: [], categories: [], posting_accounts: [] }, [{ id: 44, item_name: 'TEST service', item_type: 'SERVICE', sales_account_id: 8101, is_active: 1 }]);
  let tree = await app.load();
  const edit = find(tree, n => n.props?.children === 'تعديل' && typeof n.props?.onClick === 'function');
  edit.props.onClick(); tree = app.render();
  // AccountSelect is a pure wrapper; render it to inspect the shared selector callback.
  const wrapper = find(tree, n => n.props?.label === 'حساب المبيعات / الإيراد' && typeof n.props.onChange === 'function');
  const selector = find(wrapper.type(wrapper.props), n => n.props?.endpoint === '/items/gl-options');
  assert.equal(selector.props.value, '8101');
  selector.props.onChange('9999'); tree = app.render();
  await find(tree, n => n.props?.children === 'حفظ' && typeof n.props.onClick === 'function').props.onClick();
  assert.equal(app.requests[0].url, '/items/44');
  assert.equal(app.requests[0].payload.sales_account_id, 9999);
});

test('inventory cost selector preserves operational ID and source branch in the draft contract', async () => {
  const app = setup('../app/inventory-operations/page.tsx', { branches: [], items: [{ id: 1, item_name: 'TEST' }], inventory: [{ item_id: 1, branch_id: 7, balance_kg: 100 }], currencies: [{ currency_code: 'SAR' }], base_currency: 'SAR' });
  let tree = await app.load();
  find(tree, n => n.props?.children === '+ عملية مخزنية').props.onClick();
  app.hydrate(value => value?.operation_type === 'TRANSFER', value => ({ ...value, operation_type: 'SORTING', from_branch_id: '7', from_lines: [{ item_id: '1', qty_kg: '10' }], to_lines: [{ item_id: '1', qty_kg: '10' }], costs: [{ cost_type: 'TEST', amount: '10', currency_code: 'SAR', exchange_rate: '1', payment_status: 'PAID', financial_account_id: '31' }] }));
  tree = app.render();
  const selector = find(tree, n => n.props?.endpoint === '/inventory-operations/account-options');
  assert.equal(selector.props.branch, '7'); assert.equal(selector.props.value, '31');
  selector.props.onChange('32'); tree = app.render();
  await find(tree, n => n.type === 'button' && n.props.children === 'حفظ كمسودة').props.onClick();
  assert.equal(app.requests[0].url, '/inventory-operations');
  assert.equal(app.requests[0].payload.costs[0].financial_account_id, '32');
  assert.equal(app.requests[0].payload.from_branch_id, 7);
});

test('shipment cost uses the selected operational account and the shipment branch', async () => {
  const app = setup('../app/shipments/page.tsx', { cost_types: [{ id: 9 }], base_currency: 'SAR' });
  await app.load();
  app.hydrate(value => value === null, () => ({ shipment: { id: 14, branch_id: 7, shipment_type: 'PURCHASE', commercial_status: 'DRAFT', shipment_number: 'TEST', shipment_date: '2026-03-05', currency_code: 'SAR', supplier_id: 1 }, items: [], costs: [] }));
  let tree = app.render();
  find(tree, n => n.props?.children === '+ تكلفة شحنة').props.onClick(); tree = app.render();
  find(tree, n => n.type === 'select' && n.props.value === 'UNPAID').props.onChange({ target: { value: 'PAID' } }); tree = app.render();
  const selector = find(tree, n => n.props?.endpoint === '/shipments/account-options');
  assert.equal(selector.props.branch, '7'); selector.props.onChange('31'); tree = app.render();
  const amount = find(tree, n => n.props?.label === 'المبلغ');
  find(amount, n => n.type === 'input').props.onChange({ target: { value: '10' } }); tree = app.render();
  await find(tree, n => n.props?.children === 'حفظ التكلفة' && typeof n.props.onClick === 'function').props.onClick();
  assert.equal(app.requests[0].url, '/shipment-costs');
  assert.equal(app.requests[0].payload.financial_account_id, 31);
  assert.equal(app.requests[0].payload.shipment_id, 14);
  assert.equal(app.requests[0].payload.amount, 10);
  assert.equal(app.requests[0].payload.payee_type, null);
  assert.equal(app.requests[0].payload.payee_id, null);
});

test('shipment beneficiary is explicit, branch scoped, and independent of goods supplier', async () => {
  const app = setup('../app/shipments/page.tsx', { cost_types: [{ id: 9 }], base_currency: 'SAR',
    drivers: [{ id: 11, branch_id: 7, driver_name: 'Same' }, { id: 12, branch_id: 8, driver_name: 'Other' }, { id: 13, branch_id: null, driver_name: 'Company-wide' }] });
  await app.load();
  app.hydrate(value => value === null, () => ({ shipment: { id: 14, branch_id: 7, supplier_id: 99, shipment_type: 'PURCHASE', commercial_status: 'DRAFT', shipment_number: 'TEST', shipment_date: '2026-03-05', currency_code: 'SAR' }, items: [], costs: [] }));
  let tree = app.render();
  find(tree, n => n.props?.children === '+ تكلفة شحنة').props.onClick(); tree = app.render();
  const beneficiary = find(tree, n => n.props?.label === 'مستفيد التكلفة');
  const type = find(beneficiary, n => n.type === 'select');
  assert.equal(type.props.value, '');
  type.props.onChange({ target: { value: 'DRIVER' } }); tree = app.render();
  const selector = find(tree, n => n.props?.placeholder === 'اختر المستفيد');
  assert.deepEqual(selector.props.options.map(x => x.value), [11, 13]);
  selector.props.onChange('13'); tree = app.render();
  await find(tree, n => n.props?.children === 'حفظ التكلفة' && typeof n.props.onClick === 'function').props.onClick();
  assert.equal(app.requests[0].payload.payee_type, 'DRIVER');
  assert.equal(app.requests[0].payload.payee_id, 13);
  assert.notEqual(app.requests[0].payload.payee_id, 99);
});
