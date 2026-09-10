function fetchData(id: string): Promise<void> {
  return Promise.resolve();
}

export async function run(id: string): Promise<void> {
  await fetchData(id);
}
