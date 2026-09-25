const pMap = require("p-map");

async function fetchAll(urls) {
  return pMap(urls, (url) => fetch(url), { concurrency: 5 });
}

module.exports = { fetchAll };
