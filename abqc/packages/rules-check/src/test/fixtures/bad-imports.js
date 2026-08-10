export async function helper() {
  return 1;
}

async function main(params) {
  const { Core } = require('@adobe/aio-sdk');
  return { statusCode: 200, body: params };
}
exports.main = main;
