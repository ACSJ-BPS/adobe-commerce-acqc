async function loadUser(id) {
  return fetchUser(id).then((user) => user.name);
}

module.exports = { loadUser };
