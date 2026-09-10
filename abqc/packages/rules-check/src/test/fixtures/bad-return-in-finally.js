function loadValue() {
  try {
    return compute();
  } finally {
    return -1;
  }
}

module.exports = { loadValue };
