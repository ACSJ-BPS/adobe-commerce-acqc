exports.main = async function main(params) {
  const host = params.__ow_headers.host;
  const store = host.split('.')[0];
  return { store };
};
