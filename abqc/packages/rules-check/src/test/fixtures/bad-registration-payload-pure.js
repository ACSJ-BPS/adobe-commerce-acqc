async function main(params) {
  const config = await fetchRemoteConfig(params);
  return {
    statusCode: 200,
    body: {
      id: "vendor.my-extension",
      title: config.title,
    },
  };
}
exports.main = main;
