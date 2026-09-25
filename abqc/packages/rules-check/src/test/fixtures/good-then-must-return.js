function loadUser(id) {
  return fetchUser(id).then((user) => {
    return user.name;
  });
}

module.exports = { loadUser };
