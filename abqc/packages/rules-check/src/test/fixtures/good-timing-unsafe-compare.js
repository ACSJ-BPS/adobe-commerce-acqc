const crypto = require('crypto');

function verifyWebhook(signature, secret) {
  const a = Buffer.from(signature, 'utf8');
  const b = Buffer.from(secret, 'utf8');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

module.exports = { verifyWebhook };
