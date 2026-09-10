function fetchData() {
  return Promise.resolve(1);
}

async function load() {
  try {
    return await fetchData();
  } catch (err) {
    return 0;
  }
}
