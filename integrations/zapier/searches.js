'use strict';

const { api, data } = require('./lib/http');

module.exports = {
  customer: {
    key: 'customer',
    noun: 'Customer',
    display: { label: 'Find Customer', description: 'Finds a SureHelp customer by phone number or email.' },
    operation: {
      inputFields: [
        { key: 'phone', label: 'Phone' },
        { key: 'email', label: 'Email' },
      ],
      perform: async (z, bundle) => {
        const match = data(await z.request({ url: api('/customers/lookup'), params: { phone: bundle.inputData.phone || '', email: bundle.inputData.email || '' } })).match;
        return match ? [match] : [];
      },
      sample: { id: '01JEXAMPLECUSTOMER0000000000', name: 'Ana Lopez', matched_by: 'phone' },
    },
  },
};
