const orders = require('../../actions/orders/index');
const payments = require('../../actions/payments/index');

test('creates an order then charges payment', async () => {
  const order = await orders.main({ id: '1' });
  const payment = await payments.main({ orderId: order.id });
  expect(payment.statusCode).toBe(200);
});
