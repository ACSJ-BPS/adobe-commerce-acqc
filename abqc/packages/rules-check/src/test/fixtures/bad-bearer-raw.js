exports.main = async function main(params) {
  const auth = params.__ow_headers.authorization.split(' ')[1];
  return { auth };
};
