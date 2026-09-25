function Group({ items }) {
  return (
    <div>
      {items.map(i => <Item key={i.id} {...i} />)}
    </div>
  );
}
