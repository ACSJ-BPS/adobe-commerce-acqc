function getValue(flag) {
  if (flag) {
    return Promise.resolve(42);
  }
  return Promise.reject(new Error("no value"));
}

module.exports = { getValue };
