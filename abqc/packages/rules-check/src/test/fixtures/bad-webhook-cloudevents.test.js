const action = require('../../actions/webhook-handler');

test('handles event', async () => {
  const result = await action.main({
    type: 'com.adobe.event.created',
    source: '/events/test',
    data: { id: '123' },
  });
  expect(result.statusCode).toBe(200);
});
