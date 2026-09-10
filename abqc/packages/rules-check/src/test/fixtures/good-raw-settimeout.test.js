const { render, screen } = require('@testing-library/react');
const userEvent = require('@testing-library/user-event');

test('shows result', async () => {
  render(Search());
  await userEvent.type(screen.getByLabelText('query'), 'orders');
  expect(await screen.findByText('Found 12 orders')).toBeInTheDocument();
});
