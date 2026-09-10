exports.main = async function main(params) {
  const signature = params.__ow_headers['x-hub-signature'];
  process(signature);
  return { statusCode: 200 };
};
