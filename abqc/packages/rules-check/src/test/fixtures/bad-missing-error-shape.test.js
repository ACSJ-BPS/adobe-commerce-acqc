const { main } = require('../../actions/orders/index');

test('main returns 400 when id is missing', async () => {
  const result = await main({});
  expect(result.error).toBeDefined();
});
