const ALLOWED_ORIGINS = new Set(['https://experience.adobe.com']);

async function main(params) {
  const origin = params.__ow_headers ? params.__ow_headers.origin : '';
  const corsOrigin = ALLOWED_ORIGINS.has(origin) ? origin : 'https://experience.adobe.com';
  return {
    statusCode: 200,
    headers: {
      'access-control-allow-origin': corsOrigin,
      'content-type': 'application/json'
    },
    body: params.data
  };
}
exports.main = main;
