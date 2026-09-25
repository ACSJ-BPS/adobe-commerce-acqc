export async function getOrders() {
  return fetch('https://api.example.com/orders').then((r) => r.json());
}
