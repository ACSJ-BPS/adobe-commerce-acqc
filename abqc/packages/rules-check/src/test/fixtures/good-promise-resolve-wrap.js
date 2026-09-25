function getValue(flag) {
  if (flag) {
    return 42;
  }
  throw new Error("no value");
}

module.exports = { getValue };
