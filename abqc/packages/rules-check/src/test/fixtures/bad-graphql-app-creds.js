exports.main = async function main(params) {
  const cached = await state.get('user:' + params.userId);
  const res = await fetch('https://host/graphql', { headers: { Authorization: 'Bearer ' + cached.value } });
  return { res };
};
