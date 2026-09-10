async function main(params) {
  const imsToken = params.__ow_headers.authorization;
  console.log("token used", imsToken);
  return { statusCode: 200 };
}
exports.main = main;
