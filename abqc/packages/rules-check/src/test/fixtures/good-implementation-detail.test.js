const { render, fireEvent, screen } = require('@testing-library/react');

test('increments count', () => {
  render(Counter());
  fireEvent.click(screen.getByRole('button', { name: /increment/i }));
  expect(screen.getByText('Count: 1')).toBeInTheDocument();
});
