const data = fetch('https://host/init');
exports.main = async function main(params) {
  return { data };
};
