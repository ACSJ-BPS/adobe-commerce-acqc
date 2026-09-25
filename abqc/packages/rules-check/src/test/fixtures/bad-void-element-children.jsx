function Figure({ value, onChange }) {
  return (
    <div>
      <img src="/x.png">
        <span>caption</span>
      </img>
      <input type="text">{value}</input>
    </div>
  );
}
