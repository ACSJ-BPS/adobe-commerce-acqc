exports.main = async function main(params) {
  const sig = params.__ow_headers['x-slack-signature'];
  return { statusCode: 200 };
};
