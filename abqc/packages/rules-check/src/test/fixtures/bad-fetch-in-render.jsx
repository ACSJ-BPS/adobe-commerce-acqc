function UserCard({ id }) {
  const data = fetch(`/api/user/${id}`).then(r => r.json());
  return <div>{data?.name}</div>;
}
