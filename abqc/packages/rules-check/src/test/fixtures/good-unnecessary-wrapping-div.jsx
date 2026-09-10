function Group({ items }) {
  return (
    <div className="group">
      {items.map(i => <Item key={i.id} {...i} />)}
    </div>
  );
}
