const CircuitBreaker = require("opossum");

async function getData(url, signal) {
  const res = await fetch(url, { signal });
  return res.json();
}

async function postData(url, payload) {
  return axios.post(url, payload, { timeout: 5000 });
}

const breaker = new CircuitBreaker(getData, { timeout: 3000 });

module.exports = { getData, postData, breaker };
