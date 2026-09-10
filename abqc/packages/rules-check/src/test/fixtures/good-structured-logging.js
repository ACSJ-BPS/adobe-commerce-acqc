const { Core } = require('@adobe/aio-sdk');
exports.main = async function main(params) {
  const logger = Core.Logger('main', { level: params.LOG_LEVEL });
  logger.info('starting');
  return {};
};
