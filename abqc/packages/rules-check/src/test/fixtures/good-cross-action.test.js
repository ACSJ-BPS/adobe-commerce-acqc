const orders = require('../../actions/orders/index');

test('creates an order', async () => {
  const order = await orders.main({ id: '1' });
  expect(order.statusCode).toBe(200);
});
