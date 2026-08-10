/**
 * Main action handler.
 * @param {object} params - Input parameters
 * @returns {Promise<object>} HTTP response
 */
async function main(params) {
  return { statusCode: 200, body: params };
}
exports.main = main;
