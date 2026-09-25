function Form({ handleSubmit, openTooltip }) {
  return (
    <form onSubmit={handleSubmit}>
      <input name="email" />
      <button onClick={openTooltip}>?</button>
      <button>Save</button>
    </form>
  );
}
