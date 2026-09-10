function verifyWebhook(req, secret) {
  const signature = req.headers['x-signature'];
  if (signature === secret) {
    return true;
  }
  return false;
}

module.exports = { verifyWebhook };
