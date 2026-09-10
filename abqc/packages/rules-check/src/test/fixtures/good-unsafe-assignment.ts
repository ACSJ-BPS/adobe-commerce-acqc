import { z } from 'zod'
const Schema = z.object({ total: z.number(), user: z.object({ id: z.string() }) })
function handle (raw: string) {
  const data = Schema.parse(JSON.parse(raw))
  const total: number = data.total
  const id = data.user.id
  return { total, id }
}
