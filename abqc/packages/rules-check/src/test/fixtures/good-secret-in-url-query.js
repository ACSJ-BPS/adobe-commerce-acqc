function buildUrl(apiKey) {
  return fetch('https://api.example.com/data', {
    headers: { Authorization: `Bearer ${apiKey}` }
  });
}

module.exports = { buildUrl };
