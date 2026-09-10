function sumLines(order: number[]): number {
  return order.reduce((a, b) => a + b, 0);
}

function total(order: number[]): number {
  return sumLines(order);
}
