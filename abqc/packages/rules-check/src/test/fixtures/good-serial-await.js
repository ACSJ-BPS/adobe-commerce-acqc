exports.main = async function main(params) {
  const results = await Promise.all(params.ids.map((id) => invokeAction(id)));
  return { results };
};
