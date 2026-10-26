import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../components/inventory/ItemUnitManager.tsx', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText;
function component(states = [], api = {}) {
  let index = 0;
  const output = { exports: {} };
  const hooks = { useEffect() {}, useState(initial) { return [index < states.length ? states[index++] : (index++, initial), () => {}]; } };
  new Function('require', 'exports', 'module', compiled)(name => name === 'react' ? hooks : name === '@/app/api' ? { default: api } : require(name), output.exports, output);
  return output.exports.default;
}
const unit = { id: 1, code: 'PCS', name_ar: 'قطعة', dimension: 'COUNT' };
const definition = { schema_ready: true, mode: 'BASE_ONLY', base_unit_id: 1, units: [unit], mappings: [{ id: 42, unit_id: 1, code: 'PCS', factor_to_base: '1.000001', revision: 1 }], prices: [] };
function forms(node, output = []) {
  if (!node || typeof node !== 'object') return output;
  if (node.type === 'form') output.push(node);
  for (const child of [node.props?.children].flat(Infinity)) forms(child, output);
  return output;
}

test('unit definition interface renders Arabic labels and excludes services from picker', () => {
  const tree = component()({ items: [{ id: 1, item_name: 'Copper' }, { id: 2, item_name: 'Hidden service', item_type: 'SERVICE' }] });
  const html = renderToStaticMarkup(tree);
  assert.match(html, /معاملات التحويل/);
  assert.match(html, /Copper/);
  assert.doesNotMatch(html, /Hidden service/);
});

test('legacy and absent schema interfaces expose no writable forms', () => {
  for (const value of [{ schema_ready: false, mode: 'LEGACY_ONLY', units: [], mappings: [], prices: [] }, { ...definition, mode: 'LEGACY_ONLY' }]) {
    const tree = component(['1', value])({ items: [] });
    assert.equal(forms(tree).length, 0);
    assert.match(renderToStaticMarkup(tree), /role="status"/);
  }
});

test('conversion and price submissions keep exact decimals and do not send tenant fields', async () => {
  const calls = [];
  const api = { async post(...args) { calls.push(args); } };
  const states = ['1', definition, '1', '1.000001', '42', 'SAR', '0.000001', '999999.999999', false, '', 0];
  const tree = component(states, api)({ items: [] });
  const controls = forms(tree);
  assert.equal(controls.length, 2);
  for (const control of controls) await control.props.onSubmit({ preventDefault() {} });
  // onSubmit intentionally starts the async handler without returning its promise.
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(calls, [
    ['/items/1/units/mappings', { unit_id: 1, factor: '1.000001' }],
    ['/items/1/units/prices', { mapping_id: 42, currency_code: 'SAR', purchase_price: '0.000001', sale_price: '999999.999999' }],
  ]);
});
