async function main (params) {
  const dbUrl = process.env.NODE_ENV === 'production'
    ? 'https://prod-db.example.com'
    : 'https://stage-db.example.com'
  return { statusCode: 200, body: { dbUrl } }
}
exports.main = main
