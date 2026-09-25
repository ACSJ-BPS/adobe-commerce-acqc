const action = require('../../actions/webhook-handler');

test('handles event', async () => {
  const result = await action.main({
    specversion: '1.0',
    type: 'com.adobe.event.created',
    source: '/events/test',
    id: 'abc-123',
    time: '2024-01-01T00:00:00Z',
    data: { id: '123' },
  });
  expect(result.statusCode).toBe(200);
});
