async function main(params) {
  const token = params.__ow_headers ? params.__ow_headers.authorization : undefined;
  if (!token) {
    return { statusCode: 401, body: { error: 'missing token' } };
  }
  return { statusCode: 200, body: { ok: true } };
}
exports.main = main;
