exports.main = async function main(params) {
  const variables = { pageSize: params.pageSize };
  return query(variables);
};
