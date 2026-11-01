'use strict';

const authentication = require('./authentication');
const triggers = require('./triggers');
const creates = require('./creates');
const searches = require('./searches');

// Every request carries the business's key; SureHelp's own error message is shown in the Zap.
const addKey = (request, z, bundle) => {
  request.headers = Object.assign({}, request.headers, { Authorization: `Bearer ${bundle.authData.apiKey}`, Accept: 'application/json' });
  return request;
};

const explainErrors = (response, z) => {
  if (response.status === 401) {
    throw new z.errors.RefreshAuthError('SureHelp no longer accepts this API key. Reconnect with a new key.');
  }
  if (response.status === 403 || response.status === 422 || response.status === 404) {
    const message = (response.json && response.json.message) || `SureHelp answered ${response.status}.`;
    throw new z.errors.Error(message, 'SureHelpError', response.status);
  }
  return response;
};

module.exports = {
  version: require('./package.json').version,
  platformVersion: require('zapier-platform-core').version,
  authentication,
  beforeRequest: [addKey],
  afterResponse: [explainErrors],
  triggers,
  creates,
  searches,
};
