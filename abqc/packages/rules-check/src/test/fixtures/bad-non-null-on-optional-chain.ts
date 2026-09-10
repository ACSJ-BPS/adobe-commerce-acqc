function get(a: { b?: { c: number } }): number {
  return a?.b!.c;
}
