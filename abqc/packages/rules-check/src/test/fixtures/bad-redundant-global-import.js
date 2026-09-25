const { URL } = require("node:url");
const { Buffer } = require("node:buffer");

function build(link) {
  return new URL(link) instanceof URL && Buffer.isBuffer(Buffer.from(link));
}

module.exports = { build };
