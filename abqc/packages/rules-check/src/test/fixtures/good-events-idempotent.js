exports.main = async function main(params) {
  const seen = await state.get(params.event.id);
  if (seen) return { statusCode: 200 };
  doWork();
  return { statusCode: 200 };
};
