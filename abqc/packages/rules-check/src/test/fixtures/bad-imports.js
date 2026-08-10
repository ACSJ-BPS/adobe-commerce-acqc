async function helper() {
  return 1;
}

const { Core } = require('@adobe/aio-sdk');

async function main(params) {
  return { statusCode: 200, body: params };
}
exports.main = main;
