exports.main = async function main(params) {
  if (params.challenge) {
    return { statusCode: 200, body: { challenge: params.challenge } };
  }
  doBusinessLogic();
  return { statusCode: 200 };
};
