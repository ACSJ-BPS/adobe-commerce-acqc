const SECRET_KEYS = new Set(['DB_PASSWORD', 'API_KEY', 'CLIENT_SECRET', 'authorization']);
const redact = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, SECRET_KEYS.has(k) ? '[REDACTED]' : v]));

async function main(params) {
  logger.debug('action invoked', { params: redact(params) });
  return { statusCode: 200 };
}
exports.main = main;
