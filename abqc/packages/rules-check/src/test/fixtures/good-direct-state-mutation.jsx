function List() {
  const [items, setItems] = useState([]);

  function addItem(item) {
    setItems(prev => [...prev, item]);
  }

  return <ul>{items.map(i => <li key={i.id}>{i.name}</li>)}</ul>;
}
