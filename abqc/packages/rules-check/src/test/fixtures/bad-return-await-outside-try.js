function fetchData() {
  return Promise.resolve(1);
}

async function load() {
  return await fetchData();
}
