async function main(params) {
  const state = await State.init();
  await state.put('last_sync_time', Date.now());
  return { statusCode: 200 };
}
exports.main = main;
