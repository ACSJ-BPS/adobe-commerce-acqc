async function main (params) {
  const cached = await state.get(`agg:${params.id}`)
  if (cached) return { statusCode: 200, body: JSON.parse(cached.value) }
  const items = params.items || []
  for (const group of items) {
    for (const item of group.children) {
      processItem(item)
    }
  }
  return { statusCode: 202, body: { status: 'processing' } }
}
exports.main = main
