function isInvalid(value) {
  if (Number.isNaN(value)) {
    return true;
  }
  if (typeof value === "undefined") {
    return true;
  }
  return false;
}

module.exports = { isInvalid };
