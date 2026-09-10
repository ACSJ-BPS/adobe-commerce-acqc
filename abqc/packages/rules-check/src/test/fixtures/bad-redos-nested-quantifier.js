function buildPattern() {
  const re = new RegExp("(a+)+b");
  return re;
}

function buildDynamic(userInput) {
  const re2 = new RegExp(userInput);
  return re2.test("x");
}

module.exports = { buildPattern, buildDynamic };
