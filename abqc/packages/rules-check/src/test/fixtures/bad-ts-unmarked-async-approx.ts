function fetchOrder(id: string): Promise<string> {
  return fetch(id).then((r) => String(r));
}
