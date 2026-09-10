async function main(params) {
  const response = await fetch(params.callbackUrl);
  return { statusCode: 200, body: await response.text() };
}
exports.main = main;
