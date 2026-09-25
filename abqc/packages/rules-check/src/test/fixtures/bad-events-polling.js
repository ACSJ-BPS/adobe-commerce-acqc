exports.main = async function main(params) {
  let hasMore = true;
  while (hasMore) {
    const res = await fetch('https://api.example.com/data');
    hasMore = res.hasMore;
  }
  return {};
};
