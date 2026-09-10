const action = require('../../actions/orders/index');

test('main is exported as a function', () => {
  expect(typeof action.main).toBe('function');
});

test('main handles request', async () => {
  const result = await action.main({});
  expect(result.statusCode).toBe(200);
});
