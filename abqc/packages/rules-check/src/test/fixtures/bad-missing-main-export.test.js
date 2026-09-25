const action = require('../../actions/orders/index');

test('main handles request', async () => {
  const result = await action.main({});
  expect(result.statusCode).toBe(200);
});
