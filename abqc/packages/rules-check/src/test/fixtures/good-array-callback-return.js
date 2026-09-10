function computeTotals(items) {
  return items.map((item) => {
    return item.amount * item.qty;
  });
}

module.exports = { computeTotals };
