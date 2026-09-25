async function main(params) {
  return {
    statusCode: 200,
    headers: {
      'content-type': 'application/json'
    },
    body: { ok: true }
  };
}
exports.main = main;
