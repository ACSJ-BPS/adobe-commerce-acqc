exports.main = async function main(params) {
  return db.raw('SELECT * FROM orders WHERE id = ?', [params.id]);
};
