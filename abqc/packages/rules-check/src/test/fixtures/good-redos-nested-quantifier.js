function escapeRegex(str) {
  return str.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function buildPattern() {
  const re = new RegExp("^[a-z0-9]+$");
  return re;
}

function buildDynamic(userInput) {
  const safe = escapeRegex(userInput);
  const re2 = new RegExp(safe);
  return re2.test("x");
}

module.exports = { buildPattern, buildDynamic };
