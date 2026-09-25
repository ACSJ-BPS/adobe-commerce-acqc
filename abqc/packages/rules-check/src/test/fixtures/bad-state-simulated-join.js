exports.main = async function main(params) {
  const a = await state.get('a');
  const b = await state.get('b');
  const c = await state.get('c');
  return { a, b, c };
};
