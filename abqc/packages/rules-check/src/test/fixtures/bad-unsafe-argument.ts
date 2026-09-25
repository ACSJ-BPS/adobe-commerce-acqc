interface Order { id: string }
function chargeCard (order: Order) { return order.id }
function handle (payload: string) {
  const raw = JSON.parse(payload)
  return chargeCard(raw)
}
