function isInvalid(value) {
  if (value === NaN) {
    return true;
  }
  if (typeof value === "undefinned") {
    return true;
  }
  return false;
}

module.exports = { isInvalid };
