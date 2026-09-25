exports.main = async function main(params) {
  const rows = params.orders.map((o) => o.id);
  return { statusCode: 200, body: rows };
};
