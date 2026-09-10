async function main (params) {
  if (!params.orderId) return { error: { statusCode: 400, body: 'missing orderId' } }
  return { statusCode: 200, body: { orderId: params.orderId } }
}
exports.main = main
