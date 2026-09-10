interface Order { id: string }
async function getOrder (id: string): Promise<Order> {
  const res = await fetch(`/api/orders/${id}`)
  // @ts-expect-error intentionally unsafe: res.json() isn't validated against Order
  return res.json()
}
