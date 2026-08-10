function classifyOrder(o) {
  if (o.region === 'EU') {
    if (o.total > 1000) {
      if (o.customer.tier === 'gold') return 'eu-gold-large';
      if (o.customer.tier === 'silver') return 'eu-silver-large';
      return 'eu-large';
    }
    if (o.total > 500) {
      if (o.customer.tier === 'gold') return 'eu-gold';
      if (o.customer.tier === 'silver') return 'eu-silver';
      return 'eu-mid';
    }
    return 'eu-small';
  }
  if (o.region === 'US') {
    if (o.total > 1000) return 'us-large';
    if (o.total > 500) return 'us-mid';
    return 'us-small';
  }
  return 'other';
}
