async function fetchPricing() {
  const res = await fetch('http://partner-api.example.com/pricing');
  return res.json();
}

module.exports = { fetchPricing };
