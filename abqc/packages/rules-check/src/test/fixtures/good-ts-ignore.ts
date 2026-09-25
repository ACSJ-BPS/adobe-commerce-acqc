function parse(input: unknown): number {
  // @ts-expect-error narrowing happens below
  return input.length;
}
