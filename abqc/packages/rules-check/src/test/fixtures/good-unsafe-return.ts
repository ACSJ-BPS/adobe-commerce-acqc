import { z } from 'zod'
interface Order { id: string }
const OrderSchema = z.object({ id: z.string() })
async function getOrder (id: string): Promise<Order> {
  const res = await fetch(`/api/orders/${id}`)
  return OrderSchema.parse(await res.json())
}
