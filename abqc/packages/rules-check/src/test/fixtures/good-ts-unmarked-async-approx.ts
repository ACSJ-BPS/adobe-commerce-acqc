async function fetchOrder(id: string): Promise<string> {
  const res = await fetch(id);
  return String(res);
}
