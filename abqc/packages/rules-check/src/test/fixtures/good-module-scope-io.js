exports.main = async function main(params) {
  const data = await fetch('https://host/init');
  return { data };
};
