'use strict';

const { api, data } = require('./lib/http');

const SOURCES = { website: 'Website form', facebook: 'Facebook lead ad', google: 'Google (Local Services or Ads)', angi: 'Angi', thumbtack: 'Thumbtack', yelp: 'Yelp', nextdoor: 'Nextdoor', other: 'Other' };

module.exports = {
  lead: {
    key: 'lead',
    noun: 'Lead',
    display: {
      label: 'Add Lead',
      description: 'Adds a new lead. If Instant answer is on, SureHelp texts and emails them within seconds and can ring you to connect.',
    },
    operation: {
      inputFields: [
        { key: 'name', label: 'Name' },
        { key: 'phone', label: 'Phone', helpText: 'A phone number or an email is needed.' },
        { key: 'email', label: 'Email' },
        { key: 'message', label: 'What they asked for', type: 'text' },
        { key: 'source', label: 'Where the lead came from', choices: SOURCES, default: 'other' },
      ],
      perform: async (z, bundle) => data(await z.request({ url: api('/zapier/leads'), method: 'POST', body: bundle.inputData })),
      sample: { id: '01JEXAMPLELEAD000000000000000', name: 'Ana Lopez', phone: '+15125550147', email: 'ana@example.com', source: 'facebook', customer_id: '01JEXAMPLECUSTOMER0000000000', received_at: '2026-10-30T15:00:00+00:00' },
    },
  },
  customer: {
    key: 'customer',
    noun: 'Customer',
    display: { label: 'Add Customer', description: 'Adds a customer to SureHelp, so receptionists know them when they call.' },
    operation: {
      inputFields: [
        { key: 'first_name', label: 'First name' },
        { key: 'last_name', label: 'Last name' },
        { key: 'company', label: 'Company' },
        { key: 'phone', label: 'Phone', helpText: 'A phone number or an email is needed.' },
        { key: 'email', label: 'Email' },
      ],
      perform: async (z, bundle) => {
        const response = await z.request({ url: api('/customers'), method: 'POST', body: bundle.inputData, skipThrowForStatus: true });
        // Someone with that phone number is already there: hand back that customer instead of failing the Zap.
        if (response.status === 409 && response.json && response.json.existing) {
          return Object.assign({ already_existed: true }, response.json.existing);
        }
        response.throwForStatus();
        return data(response).customer;
      },
      sample: { id: '01JEXAMPLECUSTOMER0000000000', first_name: 'Ana', last_name: 'Lopez', phone: '+15125550147', email: 'ana@example.com' },
    },
  },
  task: {
    key: 'task',
    noun: 'Task',
    display: { label: 'Add Task', description: 'Adds a follow-up task for your team in SureHelp.' },
    operation: {
      inputFields: [
        { key: 'title', label: 'Title', required: true },
        { key: 'description', label: 'Details', type: 'text' },
        { key: 'due_at', label: 'Due', type: 'datetime' },
        { key: 'customer_id', label: 'Customer ID', helpText: 'Optional. A SureHelp customer ID, for example from "Find Customer".' },
      ],
      perform: async (z, bundle) => data(await z.request({ url: api('/tasks'), method: 'POST', body: bundle.inputData })).task,
      sample: { id: '01JEXAMPLETASK00000000000000', title: 'Call Ana back about the quote', status: 'open' },
    },
  },
};
