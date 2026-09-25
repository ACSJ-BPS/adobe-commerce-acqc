async function main (params) {
  const items = params.items || []
  for (const group of items) {
    for (const item of group.children) {
      processItem(item)
    }
  }
  return { statusCode: 200, body: { ok: true } }
}
exports.main = main
