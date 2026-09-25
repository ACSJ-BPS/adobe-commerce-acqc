const fs = require("node:fs");

async function main(params) {
  const data = fs.readFileSync("/tmp/data.json", "utf8");
  return { data, params };
}

exports.main = main;
