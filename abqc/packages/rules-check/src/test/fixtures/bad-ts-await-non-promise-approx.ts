function sumLines(order: number[]): number {
  return order.reduce((a, b) => a + b, 0);
}

async function total(order: number[]): Promise<number> {
  return await sumLines(order);
}
