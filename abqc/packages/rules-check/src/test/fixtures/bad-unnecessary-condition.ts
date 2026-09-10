interface Order { id: string }
function process (order: Order): string {
  if (order) {
    return order.id
  }
  return ''
}
