const url = require("node:url");
const crypto = require("node:crypto");
const domain = require("node:domain");
const punycode = require("node:punycode");

function legacyParse(link) {
  const parsed = url.parse(link);
  const buf = new Buffer("hi");
  const cipher = crypto.createCipher("aes-256-cbc", "password");
  const d = domain.create();
  return { parsed, buf, cipher, d, punycode };
}

module.exports = { legacyParse };
