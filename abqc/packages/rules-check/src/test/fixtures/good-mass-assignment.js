async function main(params) {
  const orderId = params.orderId;
  const allowedFields = { name: params.name, email: params.email };
  await db.orders.update(orderId, allowedFields);
  return { statusCode: 200 };
}
exports.main = main;
