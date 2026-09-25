exports.main = async function main(params) {
  const signature = params.__ow_headers['x-hub-signature'];
  doBusinessLogic(signature);
  return { statusCode: 200 };
};
