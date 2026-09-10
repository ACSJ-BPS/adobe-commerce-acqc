const fs = require("node:fs/promises");

async function main(params) {
  const data = await fs.readFile("/tmp/data.json", "utf8");
  return { data, params };
}

exports.main = main;
