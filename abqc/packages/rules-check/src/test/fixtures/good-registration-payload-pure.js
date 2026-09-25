async function main(params) {
  const result = {
    statusCode: 200,
    body: {
      id: "vendor.my-extension",
      title: "My Extension",
    },
  };
  return result;
}
exports.main = main;
