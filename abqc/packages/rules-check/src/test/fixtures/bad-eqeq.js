exports.main = async function (params) {
  if (params.x == 0) return { statusCode: 400, body: {} };
  return { statusCode: 200, body: { ok: true } };
};
