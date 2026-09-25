function outer(orderId) {
  function inner(orderId) {
    return orderId;
  }
  return inner(orderId);
}

module.exports = { outer };
