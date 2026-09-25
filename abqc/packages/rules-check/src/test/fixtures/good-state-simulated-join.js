exports.main = async function main(params) {
  const a = await state.get('a');
  return { a };
};
