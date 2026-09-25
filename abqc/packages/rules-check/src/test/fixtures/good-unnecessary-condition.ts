interface Order { id: string }
function process (order: Order): string {
  return order.id
}
