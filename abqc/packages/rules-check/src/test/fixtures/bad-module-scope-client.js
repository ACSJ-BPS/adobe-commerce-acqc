exports.main = async function main(params) {
  const client = new CommerceClient(params);
  return client.get();
};
