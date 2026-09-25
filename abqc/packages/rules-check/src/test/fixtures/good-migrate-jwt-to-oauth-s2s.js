async function getAccessToken(ims) {
  const token = await ims.getToken('oauth-server-to-server');
  return token;
}
module.exports = { getAccessToken };
