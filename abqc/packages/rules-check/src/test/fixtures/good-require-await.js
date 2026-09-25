async function getValue() {
  const result = await Promise.resolve(42);
  return result;
}

module.exports = { getValue };
