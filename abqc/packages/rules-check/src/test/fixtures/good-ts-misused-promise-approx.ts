async function isAuthorized(): Promise<boolean> {
  return true;
}

async function check(): Promise<void> {
  if (await isAuthorized()) {
    console.log('ok');
  }
}

function save(i: number): Promise<void> {
  return Promise.resolve();
}

async function saveAll(): Promise<void> {
  for (const i of [1, 2, 3]) {
    await save(i);
  }
}
