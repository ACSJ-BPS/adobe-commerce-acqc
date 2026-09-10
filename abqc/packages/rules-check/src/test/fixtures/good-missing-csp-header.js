async function main(params) {
  return {
    statusCode: 200,
    headers: {
      'content-type': 'application/json',
      'content-security-policy': "default-src 'self'"
    },
    body: { ok: true }
  };
}
exports.main = main;
