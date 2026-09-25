const { getBearerToken } = require('../utils');
exports.main = async function main(params) {
  const token = getBearerToken(params);
  return { token };
};
