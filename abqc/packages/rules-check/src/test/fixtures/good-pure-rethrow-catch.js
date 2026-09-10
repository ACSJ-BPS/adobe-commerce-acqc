async function loadData() {
  try {
    return await fetchRemote();
  } catch (err) {
    throw new Error(`failed: ${err.message}`);
  }
}

module.exports = { loadData };
