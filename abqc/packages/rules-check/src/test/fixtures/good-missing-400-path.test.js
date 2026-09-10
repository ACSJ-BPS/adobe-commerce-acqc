const { main } = require('../../actions/orders/index');

test('main returns success', async () => {
  const result = await main({ id: '123' });
  expect(result.statusCode).toBe(200);
});

test('main returns 400 when id is missing', async () => {
  const result = await main({});
  expect(result.error.statusCode).toBe(400);
});
