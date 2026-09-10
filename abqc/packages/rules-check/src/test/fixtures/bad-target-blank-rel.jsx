function Profile({ user }) {
  return <a href={user.website} target="_blank">{user.name}</a>;
}
