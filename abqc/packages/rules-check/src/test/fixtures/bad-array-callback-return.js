function computeTotals(items) {
  return items.map((item) => {
    const amount = item.amount * item.qty;
  });
}

module.exports = { computeTotals };
