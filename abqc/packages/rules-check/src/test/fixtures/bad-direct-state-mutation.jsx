function List() {
  const [items, setItems] = useState([]);

  function addItem(item) {
    items.push(item);
    setItems(items);
  }

  return <ul>{items.map(i => <li key={i.id}>{i.name}</li>)}</ul>;
}
