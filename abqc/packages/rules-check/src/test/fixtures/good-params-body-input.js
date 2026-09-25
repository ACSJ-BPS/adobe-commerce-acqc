async function main(params) {
  const input = params.value;
  return { statusCode: 200, body: input };
}
exports.main = main;
