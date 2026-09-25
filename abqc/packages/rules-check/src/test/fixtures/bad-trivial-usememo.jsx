function Price({ order }) {
  const total = useMemo(() => order.total, [order]);
  return <span>{total}</span>;
}
