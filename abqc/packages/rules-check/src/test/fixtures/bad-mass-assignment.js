async function main(params) {
  await db.orders.update(params.orderId, { ...params });
  return { statusCode: 200 };
}
exports.main = main;
