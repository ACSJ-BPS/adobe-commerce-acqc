const fs = require("node:fs/promises");

async function loadConfig(path) {
  return fs.readFile(path, "utf8");
}

module.exports = { loadConfig };
