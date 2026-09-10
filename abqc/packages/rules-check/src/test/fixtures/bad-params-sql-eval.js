exports.main = async function main(params) {
  const query = `SELECT * FROM orders WHERE id = ${params.id}`;
  return db.raw(query);
};
