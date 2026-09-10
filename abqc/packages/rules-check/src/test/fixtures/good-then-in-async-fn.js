async function loadUser(id) {
  const user = await fetchUser(id);
  return user.name;
}

module.exports = { loadUser };
