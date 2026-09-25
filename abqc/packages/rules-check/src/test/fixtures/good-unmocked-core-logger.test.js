jest.mock('@adobe/aio-sdk', () => ({
  Core: { Logger: () => ({ info: jest.fn(), error: jest.fn() }) },
}));
const { main } = require('../../actions/orders/index');

test('main returns success', async () => {
  const result = await main({});
  expect(result.statusCode).toBe(200);
});
