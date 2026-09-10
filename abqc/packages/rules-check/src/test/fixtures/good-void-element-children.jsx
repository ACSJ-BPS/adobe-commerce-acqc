function Figure({ value, onChange }) {
  return (
    <figure>
      <img src="/x.png" alt="diagram" />
      <figcaption>caption</figcaption>
      <input type="text" value={value} onChange={onChange} />
    </figure>
  );
}
