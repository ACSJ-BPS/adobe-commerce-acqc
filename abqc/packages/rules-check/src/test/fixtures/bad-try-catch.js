async function main(params) {
  const data = await fetchData(params.id);
  return { statusCode: 200, body: data };
}
exports.main = main;
