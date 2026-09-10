exports.main = async function main(params) {
  const secret = process.env.COMMERCE_APP_TOKEN;
  const res = await fetch('https://host/graphql', { headers: { Authorization: 'Bearer ' + secret } });
  return { res };
};
