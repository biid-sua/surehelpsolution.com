'use strict';

const test = require('node:test');
const assert = require('node:assert');
const schemaTools = require('zapier-platform-core/src/tools/schema');

process.env.SUREHELP_BASE_URL = 'https://surehelp.test';
const app = require('../index');

// A stand-in for Zapier's z.request that records each call and answers like SureHelp.
const fakeZ = (answer) => {
  const calls = [];
  return {
    calls,
    request: async (options) => {
      calls.push(options);
      return answer(options);
    },
  };
};

test('the app passes Zapier\'s schema check', () => {
  // The same check `zapier validate` and `zapier push` run: functions become references first.
  const errors = schemaTools.validateApp(schemaTools.prepareApp(app));
  assert.deepStrictEqual(errors.map((e) => `${e.property}: ${e.message}`), []);
});

test('every SureHelp event is a trigger', () => {
  assert.strictEqual(Object.keys(app.triggers).length, 14);
  assert.ok(app.triggers.appointment_booked && app.triggers.payment_paid && app.triggers.call_missed);
});

test('a trigger subscribes, unsubscribes and unwraps what SureHelp sends', async () => {
  const z = fakeZ(() => ({ status: 201, json: { success: true, data: { id: '01HOOK', event: 'call.logged' } } }));
  const op = app.triggers.call_logged.operation;

  const sub = await op.performSubscribe(z, { targetUrl: 'https://hooks.zapier.com/abc' });
  assert.deepStrictEqual(sub, { id: '01HOOK', event: 'call.logged' });
  assert.strictEqual(z.calls[0].url, 'https://surehelp.test/api/v1/client/zapier/hooks');
  assert.deepStrictEqual(z.calls[0].body, { target_url: 'https://hooks.zapier.com/abc', event: 'call.logged' });

  await op.performUnsubscribe(z, { subscribeData: { id: '01HOOK' } });
  assert.strictEqual(z.calls[1].url, 'https://surehelp.test/api/v1/client/zapier/hooks/01HOOK');
  assert.strictEqual(z.calls[1].method, 'DELETE');

  const rows = op.perform(z, { cleanedRequest: { id: 'evt_1', event: 'call.logged', created_at: 'now', data: { type: 'call', id: 'CALL-9', caller_name: 'Ana' } } });
  assert.deepStrictEqual(rows, [{ id: 'evt_1', event: 'call.logged', created_at: 'now', record_id: 'CALL-9', type: 'call', caller_name: 'Ana' }]);
});

test('adding a customer who already exists hands back that customer', async () => {
  const z = fakeZ(() => ({ status: 409, json: { success: false, message: 'taken', existing: { id: '01ANA', name: 'Ana Lopez' } }, throwForStatus: () => { throw new Error('should not throw'); } }));
  const result = await app.creates.customer.operation.perform(z, { inputData: { first_name: 'Ana', phone: '5125550147' } });
  assert.deepStrictEqual(result, { already_existed: true, id: '01ANA', name: 'Ana Lopez' });
});

test('every request carries the key', () => {
  const request = app.beforeRequest[0]({ url: 'x', headers: {} }, null, { authData: { apiKey: 'shk_abc' } });
  assert.strictEqual(request.headers.Authorization, 'Bearer shk_abc');
});
