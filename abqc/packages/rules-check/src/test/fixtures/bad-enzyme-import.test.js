const { mount } = require('enzyme');

test('renders order id', () => {
  const wrapper = mount(OrderRow({ order: fixture }));
  expect(wrapper.find('.order-id').text()).toBe(fixture.id);
});
