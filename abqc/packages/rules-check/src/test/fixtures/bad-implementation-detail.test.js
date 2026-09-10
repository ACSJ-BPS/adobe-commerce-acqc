const React = require('react');
const { render, fireEvent, screen } = require('@testing-library/react');

test('increments count', () => {
  const setCount = jest.fn();
  jest.spyOn(React, 'useState').mockReturnValue([0, setCount]);
  render(Counter());
  fireEvent.click(screen.getByRole('button'));
  expect(setCount).toHaveBeenCalledWith(1);
});

test('reads internal state', () => {
  const wrapper = mount(Counter());
  expect(wrapper.state).toBe(0);
});
