const { render, screen } = require('@testing-library/react');

test('renders order id', () => {
  render(OrderRow({ order: fixture }));
  expect(screen.getByText(fixture.id)).toBeInTheDocument();
});
