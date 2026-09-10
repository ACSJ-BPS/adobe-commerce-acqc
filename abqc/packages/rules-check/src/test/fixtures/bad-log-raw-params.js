async function main(params) {
  logger.debug('action invoked', { params });
  return { statusCode: 200 };
}
exports.main = main;
