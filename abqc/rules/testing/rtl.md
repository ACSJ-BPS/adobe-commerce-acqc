# Testing — React Testing Library

> Part of [Testing](./README.md).

## React tests use Testing Library queries — never enzyme or raw DOM [error] [react, conventions]

React Testing Library queries by role/text/label encourage testing what users see rather than implementation details. Enzyme (deprecated for React 19) and raw `container.querySelector` couple tests to the DOM tree, breaking on safe refactors.

### Bad
```js
const wrapper = mount(<OrderRow order={fixture} />)
expect(wrapper.find('.order-id').text()).toBe(fixture.id)
```

### Good
```js
const { getByRole, getByText } = render(<OrderRow order={fixture} />)
expect(getByRole('row')).toHaveTextContent(fixture.id)
expect(getByText(fixture.customer)).toBeInTheDocument()
```

---

## Test user behavior, not implementation details [warn] [react, philosophy]

Asserting "useState was called with X" or "this private method ran" couples tests to implementation. If you refactor the component to use `useReducer` or move logic to a custom hook, the test breaks even though the behavior is unchanged. Test the visible effect: clicking a button shows a confirmation, typing in a field updates the displayed value.

### Bad
```js
test('increments count', () => {
  const setCount = jest.fn()
  jest.spyOn(React, 'useState').mockReturnValue([0, setCount])
  render(<Counter />)
  fireEvent.click(screen.getByRole('button'))
  expect(setCount).toHaveBeenCalledWith(1)
})
```

### Good
```js
test('increments count', async () => {
  const user = userEvent.setup()
  render(<Counter />)
  await user.click(screen.getByRole('button', { name: /increment/i }))
  expect(screen.getByText('Count: 1')).toBeInTheDocument()
})
```

---

## Use waitFor / findBy for async UI — never raw setTimeout [error] [async, flaky]

`setTimeout(() => expect(...), 1000)` makes tests slow (always waits the full timeout) and flaky (asserts before the UI is ready on slow machines). `findBy*` queries and `waitFor` retry until the condition holds or the test times out.

### Bad
```js
test('shows result', async () => {
  render(<Search />)
  fireEvent.change(screen.getByLabelText('query'), { target: { value: 'orders' } })
  await new Promise(r => setTimeout(r, 1000))
  expect(screen.getByText('Found 12 orders')).toBeInTheDocument()
})
```

### Good
```js
test('shows result', async () => {
  render(<Search />)
  await userEvent.type(screen.getByLabelText('query'), 'orders')
  expect(await screen.findByText('Found 12 orders')).toBeInTheDocument()
})
```
