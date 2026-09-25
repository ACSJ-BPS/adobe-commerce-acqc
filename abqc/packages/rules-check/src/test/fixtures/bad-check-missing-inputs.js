exports.main = async function main(params) {
  const client = createClient(params);
  checkMissingRequestInputs(params, ['id']);
  return client.get();
};
