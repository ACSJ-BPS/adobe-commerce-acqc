function Row({ item }) {
  return <li>{item.name}</li>;
}

function ParentList({ items }) {
  return <ul>{items.map(i => <Row key={i.id} item={i} />)}</ul>;
}
