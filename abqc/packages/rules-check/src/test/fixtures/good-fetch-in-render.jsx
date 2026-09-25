function UserCard({ id }) {
  const [data, setData] = useState(null);
  useEffect(() => {
    const ctrl = new AbortController();
    fetch(`/api/user/${id}`, { signal: ctrl.signal }).then(r => r.json()).then(setData).catch(() => {});
    return () => ctrl.abort();
  }, [id]);
  return <div>{data?.name}</div>;
}
