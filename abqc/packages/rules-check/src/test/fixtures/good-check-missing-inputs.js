exports.main = async function main(params) {
  checkMissingRequestInputs(params, ['id']);
  const client = createClient(params);
  return client.get();
};
