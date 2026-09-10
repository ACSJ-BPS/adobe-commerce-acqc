function describe () {
  const order: { id: number; total: number } = { id: 42, total: 99 }
  const msg = `Processing order ${order.id} ($${order.total})`
  return msg
}
