function Form({ handleSubmit, openTooltip }) {
  return (
    <form onSubmit={handleSubmit}>
      <input name="email" />
      <button type="button" onClick={openTooltip}>?</button>
      <button type="submit">Save</button>
    </form>
  );
}
