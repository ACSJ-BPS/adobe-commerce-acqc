async function loadOrderWithUser(orderId) {
  const order = await fetchOrder(orderId);
  const user = await fetchUser(order.userId);
  return { order, user };
}

module.exports = { loadOrderWithUser };
