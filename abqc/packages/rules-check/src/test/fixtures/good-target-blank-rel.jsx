function Profile({ user }) {
  return <a href={user.website} target="_blank" rel="noopener noreferrer">{user.name}</a>;
}
