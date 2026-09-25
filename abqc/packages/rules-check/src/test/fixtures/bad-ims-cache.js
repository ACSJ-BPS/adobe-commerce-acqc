let cachedToken = null;
async function fetchToken() {
  cachedToken = await imsRawRequest();
  cachedToken.expiresAt = Date.now() + 86400000;
}
function isExpired() {
  return Date.now() > cachedToken.expiresAt;
}
exports.main = async function main(params) { return {}; };
