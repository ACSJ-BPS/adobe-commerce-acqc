async function loadData() {
  try {
    return await fetchRemote();
  } catch (err) {
    throw err;
  }
}

module.exports = { loadData };
