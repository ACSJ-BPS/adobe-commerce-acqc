async function getAccessToken(ims) {
  const token = await ims.getToken('jwt');
  return token;
}
module.exports = { getAccessToken };
