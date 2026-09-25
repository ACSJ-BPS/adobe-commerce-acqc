exports.main = async function main(params) {
  await state.put('orders:' + params.id, params.data);
  const saved = await state.get('orders:' + params.id);
  return { saved };
};
