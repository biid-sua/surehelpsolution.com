'use strict';

// Where SureHelp lives. `zapier env:set 1.0.0 SUREHELP_BASE_URL=https://staging.example.com` points a
// version somewhere else while testing.
const baseUrl = () => (process.env.SUREHELP_BASE_URL || 'https://surehelpsolution.com').replace(/\/+$/, '');

const api = (path) => `${baseUrl()}/api/v1/client${path}`;

// The business API answers {success, data, message}; Zapier wants the data itself.
const data = (response) => {
  const body = response.json || {};
  return body.data !== undefined ? body.data : body;
};

module.exports = { baseUrl, api, data };
