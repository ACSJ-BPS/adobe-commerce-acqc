function outer(orderId) {
  function inner(itemId) {
    return itemId;
  }
  return inner(orderId);
}

module.exports = { outer };
