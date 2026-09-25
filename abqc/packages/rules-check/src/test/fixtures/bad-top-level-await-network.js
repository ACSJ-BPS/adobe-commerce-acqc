const fetch = require("node-fetch");

const data = await fetch("https://example.com/api");

async function main(params) {
  return { data, params };
}

exports.main = main;
