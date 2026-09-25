function handleEvent(payload) {
  notify(payload);
  return true;
}

module.exports = { handleEvent };
