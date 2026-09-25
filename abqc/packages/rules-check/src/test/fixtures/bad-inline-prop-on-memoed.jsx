import React from 'react';

function ExpensiveListImpl({ items, style }) {
  return (
    <ul style={style}>
      {items.map(i => <li key={i.id}>{i.name}</li>)}
    </ul>
  );
}

const Memoed = React.memo(ExpensiveListImpl);

function Page() {
  return <Memoed items={[1, 2, 3]} style={{ margin: 8 }} onSelect={(id) => doThing(id)} />;
}
