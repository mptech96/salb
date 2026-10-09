import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import ts from 'typescript';
const source = fs.readFileSync(new URL('../lib/expense-meta.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
const helper = { exports: {} };
new Function('exports', 'module', compiled)(helper.exports, helper);
const { expenseMetaArray, normalizeExpenseMeta, expensePaymentAccounts } = helper.exports;
const accounts = [
  { id: 1, branch_id: null, is_active: 1, account_type: 'OTHER', account_name: 'سلف' },
  { id: 2, branch_id: 7, is_active: true, account_type: 'PETTY_CASH', account_name: 'عهد' },
  { id: 3, branch_id: 8, is_active: 1 },
  { id: 4, branch_id: 7, is_active: 0 },
];
function response(financial_accounts = accounts) {
  return { status: true, data: { financial_accounts, branches: [], types: [], shipments: [], cars: [], purchases: [], sales: [], drivers: [], workers: [], currencies: [] } };
}
test('normalizes direct arrays and complete Laravel pagination without losing accounts', () => {
  assert.deepEqual(normalizeExpenseMeta(response()).financial_accounts, accounts);
  assert.deepEqual(normalizeExpenseMeta(response({ data: accounts, current_page: 1, total: 4 })).financial_accounts, accounts);
  assert.deepEqual(expenseMetaArray({ data: accounts }, 'accounts'), accounts);
});
test('rejects incomplete pagination instead of silently hiding later accounts', () => {
  assert.throws(() => normalizeExpenseMeta(response({ data: accounts, total: 30 })), /غير مكتملة/);
});
test('API and permission failures retain their visible error messages', () => {
  assert.throws(() => normalizeExpenseMeta({ status: false, message: 'غير مصرح' }), /غير مصرح/);
  assert.throws(() => expenseMetaArray({ status: false, message: 'غير مصرح' }, 'accounts'), /غير مصرح/);
});
test('malformed, absent and non-array data fail instead of becoming empty options', () => {
  for (const value of [undefined, null, {}, { data: {} }, { data: null }, 'error', [null]]) {
    assert.throws(() => expenseMetaArray(value, 'accounts'));
  }
  assert.throws(() => normalizeExpenseMeta({ status: true, data: {} }));
});
test('valid empty lists remain valid', () => {
  assert.deepEqual(normalizeExpenseMeta(response([])).financial_accounts, []);
});
test('new/edit selectors preserve petty-cash and advances and reject inactive/foreign branches', () => {
  const selected = expensePaymentAccounts(accounts, '7');
  assert.deepEqual(selected.map(account => account.id), [1, 2]);
  assert.equal(selected.find(account => account.id === 2).account_type, 'PETTY_CASH');
  assert.equal(selected.find(account => account.id === 1).account_type, 'OTHER');
  assert.deepEqual(expensePaymentAccounts(accounts, '8').map(account => account.id), [1, 3]);
});
