exports.main = async function main(params) {
  await db.query('INSERT INTO orders VALUES (?)', [params.data]);
  return {};
};
