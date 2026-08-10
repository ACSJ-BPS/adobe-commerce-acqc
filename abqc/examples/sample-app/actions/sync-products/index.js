// Sample App Builder action with deliberate violations for smoke testing.
const fs = require('fs');

exports.main = async function (params) {
  // Deliberate: eval — flagged by no-eval
  const userInput = params.expr;
  const result = eval(userInput);

  // Deliberate: == instead of === — flagged by eqeqeq
  if (result == null) {
    return { statusCode: 400, body: { error: 'no result' } };
  }

  // Deliberate: undefined variable — flagged by no-undef
  return { statusCode: 200, body: { result, version: APP_VERSION } };
};
