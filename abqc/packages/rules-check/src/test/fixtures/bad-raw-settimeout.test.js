const { render, fireEvent, screen } = require('@testing-library/react');

test('shows result', async () => {
  render(Search());
  fireEvent.change(screen.getByLabelText('query'), { target: { value: 'orders' } });
  await new Promise((resolve) => setTimeout(resolve, 1000));
  expect(screen.getByText('Found 12 orders')).toBeInTheDocument();
});
