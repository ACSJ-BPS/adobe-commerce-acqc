/**
 * Run the action.
 * @param {object} params - Input parameters
 * @returns {Promise<object>} HTTP response
 */
async function main(params) {
  try {
    return { statusCode: 200, body: params };
  } catch (e) {
    return { statusCode: 500, body: { error: String(e) } };
  }
}

exports.main = main;
