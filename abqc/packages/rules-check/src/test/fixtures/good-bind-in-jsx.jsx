function Row({ item }) {
  const handleSelect = useCallback((id) => doThing(id), []);
  return <MemoedRow onSelect={handleSelect} item={item} />;
}
