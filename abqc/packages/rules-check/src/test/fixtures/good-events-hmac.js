const { timingSafeEqual } = require('crypto');
exports.main = async function main(params) {
  const signature = params.__ow_headers['x-hub-signature'];
  timingSafeEqual(Buffer.from(signature), Buffer.from('b'));
  return { statusCode: 200 };
};
