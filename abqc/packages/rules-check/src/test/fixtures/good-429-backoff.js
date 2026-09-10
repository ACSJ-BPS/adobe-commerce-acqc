exports.main = async function main(params) {
  while (true) {
    const res = await callApi();
    if (res.status === 429) {
      await new Promise((r) => setTimeout(r, 1000));
      continue;
    }
    break;
  }
  return {};
};
