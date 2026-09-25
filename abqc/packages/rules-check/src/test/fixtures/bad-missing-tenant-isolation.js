async function main(params) {
  const orderId = params.orderId;
  const order = await db.getOrder(orderId);
  return { statusCode: 200, body: order };
}
exports.main = main;
