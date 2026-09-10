const { timingSafeEqual } = require('crypto');
exports.main = async function main(params) {
  const sig = params.__ow_headers['x-slack-signature'];
  if (params.challenge) {
    return { statusCode: 200 };
  }
  timingSafeEqual(Buffer.from(sig), Buffer.from('x'));
  return { statusCode: 200 };
};
