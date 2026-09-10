import React, { useCallback } from 'react';

function ExpensiveListImpl({ items, style }) {
  return (
    <ul style={style}>
      {items.map(i => <li key={i.id}>{i.name}</li>)}
    </ul>
  );
}

const Memoed = React.memo(ExpensiveListImpl);
const items = [1, 2, 3];
const listStyle = { margin: 8 };

function Page() {
  const handleSelect = useCallback((id) => doThing(id), []);
  return <Memoed items={items} style={listStyle} onSelect={handleSelect} />;
}
