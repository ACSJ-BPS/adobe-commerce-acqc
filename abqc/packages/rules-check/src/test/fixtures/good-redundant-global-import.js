const { URL, URLSearchParams } = require("node:url");

function build(link) {
  const parsed = new URL(link);
  return new URLSearchParams(parsed.search);
}

module.exports = { build };
