async function main(params) {
  const secret = await getImsToken(params);
  return {
    statusCode: 200,
    body: {
      token: secret,
      data: {},
    },
  };
}
exports.main = main;
