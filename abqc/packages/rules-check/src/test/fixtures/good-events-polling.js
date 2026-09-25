exports.main = async function main(params) {
  const res = await fetch('https://api.example.com/data');
  return { data: res };
};
