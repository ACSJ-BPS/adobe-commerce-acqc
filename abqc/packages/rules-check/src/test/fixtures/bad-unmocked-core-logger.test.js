const { main } = require('../../actions/orders/index');

test('main returns success', async () => {
  const result = await main({});
  expect(result.statusCode).toBe(200);
});
