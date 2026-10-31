'use strict';

const { api, data } = require('./lib/http');
const events = require('./lib/events');

// Each trigger is a REST hook: turning the Zap on adds a webhook address in SureHelp, turning it off
// removes it. SureHelp posts {id, event, created_at, organization, data}. The Zap sees the event's id
// (Zapier skips ids it has seen, and one booking can be moved twice) with the record's id as record_id.
const trigger = ({ event, key, noun, label, description }) => ({
  key,
  noun,
  display: { label, description },
  operation: {
    type: 'hook',
    performSubscribe: async (z, bundle) =>
      data(await z.request({ url: api('/zapier/hooks'), method: 'POST', body: { target_url: bundle.targetUrl, event } })),
    performUnsubscribe: async (z, bundle) =>
      data(await z.request({ url: api(`/zapier/hooks/${bundle.subscribeData.id}`), method: 'DELETE' })),
    perform: (z, bundle) => {
      const body = bundle.cleanedRequest || {};
      const record = Object.assign({}, body.data || {});
      const recordId = record.id;
      delete record.id;
      return [Object.assign({ id: body.id, event: body.event, created_at: body.created_at, record_id: recordId }, record)];
    },
    performList: async (z) => data(await z.request({ url: api('/zapier/sample'), params: { event } })),
    sample: { id: 'evt_sample', event, type: noun.toLowerCase() },
  },
});

module.exports = Object.fromEntries(events.map((e) => [e.key, trigger(e)]));
