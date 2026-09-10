function Badge({ messages }) {
  return <div>{messages.length && <span>{messages.length}</span>}</div>;
}
