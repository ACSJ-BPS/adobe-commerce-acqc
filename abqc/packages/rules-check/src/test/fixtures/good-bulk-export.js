exports.main = async function main(params) {
  const rows = params.orders.map((o) => o.id);
  await files.write('export.json', JSON.stringify(rows));
  return { statusCode: 200, body: { url: 'https://files/export.json' } };
};
