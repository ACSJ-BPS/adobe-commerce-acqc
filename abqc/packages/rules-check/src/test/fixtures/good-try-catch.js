async function main(params) {
  try {
    const data = await fetchData(params.id);
    return { statusCode: 200, body: data };
  } catch (e) {
    return { statusCode: 500, body: { error: String(e) } };
  }
}
exports.main = main;
