'use strict';

const { api, data } = require('./lib/http');

// A SureHelp API key, made under Business › API & webhooks. Triggers need a key whose owner can manage
// integrations; actions that add things need "Can add and change things".
module.exports = {
  type: 'custom',
  fields: [
    {
      key: 'apiKey',
      label: 'API key',
      required: true,
      type: 'password',
      helpText: 'In SureHelp, open **Business › API & webhooks**, create a key with **Can add and change things** ticked, and paste it here. It starts with `shk_`.',
    },
  ],
  test: async (z) => data(await z.request({ url: api('/zapier/me') })),
  connectionLabel: '{{business}}',
};
