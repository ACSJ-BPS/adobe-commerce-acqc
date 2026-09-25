const { main } = require('../../actions/orders/index');

test('main returns success', async () => {
  const result = await main({ id: '123' });
  expect(result.statusCode).toBe(200);
});
