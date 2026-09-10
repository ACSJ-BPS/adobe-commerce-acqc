import { z } from 'zod'
interface Order { id: string }
const OrderSchema = z.object({ id: z.string() })
function chargeCard (order: Order) { return order.id }
function handle (payload: string) {
  const order = OrderSchema.parse(JSON.parse(payload))
  return chargeCard(order)
}
