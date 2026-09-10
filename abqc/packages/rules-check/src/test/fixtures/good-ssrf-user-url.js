const allowedHosts = new Set(['api.trusted-partner.com']);

function isAllowedUrl(url) {
  try {
    return allowedHosts.has(new URL(url).host);
  } catch (e) {
    return false;
  }
}

async function main(params) {
  if (!isAllowedUrl(params.callbackUrl)) {
    return { statusCode: 400, body: { error: 'url not allowed' } };
  }
  const response = await fetch(params.callbackUrl);
  return { statusCode: 200, body: await response.text() };
}
exports.main = main;
