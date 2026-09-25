function Badge({ messages }) {
  return <div>{messages.length > 0 && <span>{messages.length}</span>}</div>;
}
