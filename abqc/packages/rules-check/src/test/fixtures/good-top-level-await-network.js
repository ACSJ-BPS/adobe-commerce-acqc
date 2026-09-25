const fetch = require("node-fetch");

async function main(params) {
  const data = await fetch("https://example.com/api");
  return { data, params };
}

exports.main = main;
