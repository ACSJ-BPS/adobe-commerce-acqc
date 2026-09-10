jest.mock('axios');
const action = require('../../actions/orders/index');

test('main fetches orders', async () => {
  const result = await action.main({});
  expect(result.statusCode).toBe(200);
});
