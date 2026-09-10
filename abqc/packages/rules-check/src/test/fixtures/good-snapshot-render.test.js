const { render } = require('@testing-library/react');
const OrderRow = require('../OrderRow');

test('renders order id and total', () => {
  const { getByText } = render(OrderRow({ order: fixture }));
  expect(getByText(fixture.id)).toBeInTheDocument();
  expect(getByText(`$${fixture.total}`)).toBeInTheDocument();
});
