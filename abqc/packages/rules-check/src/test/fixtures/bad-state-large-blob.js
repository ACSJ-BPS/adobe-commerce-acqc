exports.main = async function main(params) {
  const res = await fetch('https://host/data');
  const data = await res.json();
  await state.put('cache', data);
  return {};
};
