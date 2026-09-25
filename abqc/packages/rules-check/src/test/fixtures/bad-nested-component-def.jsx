function ParentList({ items }) {
  function Row({ item }) {
    return <li>{item.name}</li>;
  }
  return <ul>{items.map(i => <Row key={i.id} item={i} />)}</ul>;
}
