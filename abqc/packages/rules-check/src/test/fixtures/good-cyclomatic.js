function classifyOrder(o) {
  if (o.region === 'EU' && o.total > 1000) return 'eu-large';
  if (o.region === 'US') return 'us';
  return 'other';
}
