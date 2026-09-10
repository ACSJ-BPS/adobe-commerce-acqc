const { render } = require('@testing-library/react');
const OrderRow = require('../OrderRow');

test('renders order', () => {
  const { container } = render(OrderRow({ order: fixture }));
  expect(container).toMatchSnapshot();
});
