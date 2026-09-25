async function getData(url) {
  const res = await fetch(url);
  return res.json();
}

async function postData(url, payload) {
  return axios.post(url, payload);
}

async function fetchAllWithRetry(url) {
  let attempt = 0;
  while (attempt < 5) {
    attempt++;
    try {
      return await fetch(url);
    } catch (e) {
      // retry
    }
  }
}

module.exports = { getData, postData, fetchAllWithRetry };
