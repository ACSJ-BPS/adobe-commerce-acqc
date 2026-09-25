function OrderTable({ tenThousandRows }) {
  return (
    <ul>
      {tenThousandRows.map(r => <li key={r.id}>{r.name}</li>)}
    </ul>
  );
}
