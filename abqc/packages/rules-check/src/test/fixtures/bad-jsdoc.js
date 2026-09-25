export async function helper() {
  return 1;
}

async function main(params) {
  return { statusCode: 200, body: params };
}
exports.main = main;
