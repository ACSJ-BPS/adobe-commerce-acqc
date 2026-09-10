async function handleEvent(payload) {
  await notify(payload);
  return true;
}

module.exports = { handleEvent };
