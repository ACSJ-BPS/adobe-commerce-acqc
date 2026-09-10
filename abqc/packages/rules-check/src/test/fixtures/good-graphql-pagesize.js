exports.main = async function main(params) {
  const variables = { pageSize: Math.min(params.pageSize, 100) };
  return query(variables);
};
