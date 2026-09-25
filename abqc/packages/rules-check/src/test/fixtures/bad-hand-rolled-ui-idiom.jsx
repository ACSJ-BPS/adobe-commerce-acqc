function MyButton({ onClick, children }) {
  return <div className="btn" onClick={onClick}>{children}</div>;
}

function Modal({ children }) {
  return <div role="dialog">{children}</div>;
}

function CustomToggle({ pressed, onChange }) {
  return <input type="checkbox" checked={pressed} onChange={onChange} className="toggle" />;
}
