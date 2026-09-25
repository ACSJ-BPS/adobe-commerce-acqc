async function main(params) {
  const scope = 'openid read_organizations';
  return { statusCode: 200, body: { scope } };
}
exports.main = main;
