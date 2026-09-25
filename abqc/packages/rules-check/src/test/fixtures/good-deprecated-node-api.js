function modernParse(link) {
  const parsed = new URL(link);
  const buf = Buffer.from("hi");
  return { parsed, buf };
}

module.exports = { modernParse };
