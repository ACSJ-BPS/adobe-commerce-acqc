function loadOrderWithUser(orderId) {
  return fetchOrder(orderId).then((order) => {
    return fetchUser(order.userId).then((user) => {
      return { order, user };
    });
  });
}

module.exports = { loadOrderWithUser };
