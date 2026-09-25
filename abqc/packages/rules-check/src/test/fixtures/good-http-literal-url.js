async function fetchPricing() {
  const res = await fetch('https://partner-api.example.com/pricing');
  return res.json();
}

module.exports = { fetchPricing };
