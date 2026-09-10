function loadValue() {
  let result;
  try {
    result = compute();
  } finally {
    cleanup();
  }
  return result;
}

module.exports = { loadValue };
