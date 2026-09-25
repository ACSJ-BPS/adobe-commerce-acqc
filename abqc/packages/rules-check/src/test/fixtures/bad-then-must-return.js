function loadUser(id) {
  return fetchUser(id).then((user) => {
    console.log(user);
  });
}

module.exports = { loadUser };
