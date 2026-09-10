exports.main = async function main(params) {
  const results = [];
  for (const id of params.ids) {
    const r = await invokeAction(id);
    results.push(r);
  }
  return { results };
};
