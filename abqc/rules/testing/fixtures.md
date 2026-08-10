# Testing — Fixtures

> Part of [Testing](./README.md).

## Test against the CloudEvents spec for I/O Events handler payloads [warn] [events, contracts]

Adobe I/O Events delivers payloads in [CloudEvents v1.0](https://cloudevents.io/) format: `id`, `source`, `type`, `specversion`, `data`, plus `data.@type` for Adobe-specific event categories. Hand-rolled fixtures often miss required fields; tests pass but production events with the full envelope behave differently. Use a real CloudEvents fixture and validate against the published schema.

### Bad
```js
test('handles asset event', async () => {
  const result = await action.main({ assetPath: '/content/asset.jpg' })   // not a CloudEvents payload
  expect(result.statusCode).toBe(200)
})
```

### Good
```js
const cloudEvent = {
  specversion: '1.0',
  type: 'aem.assets.asset.processing_completed',
  source: 'acct:aem-p123-e456@adobe.com',
  id: '12345-uuid',
  time: '2026-04-29T10:30:00Z',
  data: {
    '@type': 'xdmAsset',
    'xdmAsset:asset_name': 'asset.jpg',
    'xdmAsset:path': '/content/dam/asset.jpg'
  }
}

test('handles asset event', async () => {
  const result = await action.main(cloudEvent)
  expect(result.statusCode).toBe(200)
  expect(mockProcessAsset).toHaveBeenCalledWith(expect.objectContaining({
    name: 'asset.jpg',
    path: '/content/dam/asset.jpg'
  }))
})
```
