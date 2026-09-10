const axios = require('axios');

async function main(params) {
  const res = await axios.get('https://api.example.com/orders');
  return { statusCode: 200, body: res.data };
}

exports.main = main;
