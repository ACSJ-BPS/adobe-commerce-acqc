exports.main = async function main(params) {
  let cursor = null;
  let page = { hasNextPage: true };
  while (page.hasNextPage) {
    page = await fetchPage(cursor);
  }
  return {};
};
