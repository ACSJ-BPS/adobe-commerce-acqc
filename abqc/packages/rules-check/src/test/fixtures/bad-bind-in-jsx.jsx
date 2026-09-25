function Row({ item, handleSelect }) {
  return <MemoedRow item={item} onSelect={handleSelect.bind(null, item.id)} />;
}
