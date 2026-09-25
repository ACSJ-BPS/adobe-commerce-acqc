async function main (params) {
  const dbUrl = params.dbUrl
  return { statusCode: 200, body: { dbUrl } }
}
exports.main = main
