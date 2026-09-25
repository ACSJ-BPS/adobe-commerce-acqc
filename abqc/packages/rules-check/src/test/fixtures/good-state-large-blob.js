exports.main = async function main(params) {
  await state.put('flag', true);
  return {};
};
