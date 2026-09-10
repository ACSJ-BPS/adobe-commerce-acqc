const { z } = require('zod')

const InputSchema = z.object({ orderId: z.string().uuid() })

async function main (params) {
  const parsed = InputSchema.safeParse(params)
  if (!parsed.success) return { error: { statusCode: 400, body: parsed.error.format() } }
  return { statusCode: 200, body: { orderId: params.orderId } }
}
module.exports = { main, InputSchema }
