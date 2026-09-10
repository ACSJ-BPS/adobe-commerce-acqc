async function main(params) {
  const input = params.body.value;
  return { statusCode: 200, body: input };
}
exports.main = main;
