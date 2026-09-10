function SortedList({ items }) {
  const sorted = useMemo(() => items.slice().sort((a, b) => a.value - b.value), [items]);
  return <ul>{sorted.map(i => <li key={i.id}>{i.name}</li>)}</ul>;
}
