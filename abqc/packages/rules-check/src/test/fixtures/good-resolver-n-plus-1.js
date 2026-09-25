async function batchOrders(root, args, context) {
  const response = await fetch(`https://api.example.com/orders?ids=${args.ids.join(",")}`);
  const all = await response.json();
  return args.ids.map((id) => all.find((o) => o.id === id));
}
module.exports = { batchOrders };
