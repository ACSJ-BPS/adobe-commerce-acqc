async function main(params) {
  const total = params.order.lines.reduce((sum, line) => sum + line.amount, 0);
  return { total };
}

exports.main = main;
