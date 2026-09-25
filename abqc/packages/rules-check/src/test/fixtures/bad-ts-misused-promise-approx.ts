// @ts-nocheck -- fixture intentionally demonstrates a pattern real tsc already
// flags (TS2801 "condition will always return true"); kept for the
// syntax-level checker under test, which has no type-checker to rely on.
async function isAuthorized(): Promise<boolean> {
  return true;
}

function check(): void {
  if (isAuthorized()) {
    console.log('ok');
  }
}

function save(i: number): Promise<void> {
  return Promise.resolve();
}

[1, 2, 3].forEach(async (i) => {
  await save(i);
});
