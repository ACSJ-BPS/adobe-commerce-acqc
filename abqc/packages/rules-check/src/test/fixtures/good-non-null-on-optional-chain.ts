function get(a: { b?: { c: number } }): number | undefined {
  return a?.b?.c;
}
