const { getToken } = require('@adobe/aio-lib-ims');
exports.main = async function main(params) {
  const token = await getToken('my-ims-context');
  return { token };
};
