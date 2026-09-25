async function main(params) {
  const state = await State.init();
  await state.put('db_password', params.dbPassword);
  return { statusCode: 200 };
}
exports.main = main;
