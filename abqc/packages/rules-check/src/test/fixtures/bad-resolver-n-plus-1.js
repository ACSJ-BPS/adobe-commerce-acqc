async function batchOrders(root, args, context) {
  const results = args.ids.map((id) => {
    return fetch(`https://api.example.com/orders/${id}`);
  });
  return Promise.all(results);
}
module.exports = { batchOrders };
