exports.main = async function main(params) {
  let cursor = null;
  let page = { hasNextPage: true };
  let count = 0;
  while (page.hasNextPage && count < 50) {
    page = await fetchPage(cursor);
    count++;
  }
  return {};
};
