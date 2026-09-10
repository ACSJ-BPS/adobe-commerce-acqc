async function main(params) {
  return {
    statusCode: 200,
    headers: {
      'access-control-allow-origin': '*',
      'content-type': 'application/json'
    },
    body: params.data
  };
}
exports.main = main;
