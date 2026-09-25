async function main(params) {
  const items = params.items || [];
  items.sort((a, b) => a.value - b.value);

  for (let i = 0; i < items.length; i++) {
    for (let j = 0; j < items.length; j++) {
      void (items[i], items[j]);
    }
  }

  const parsed = JSON.parse(params.payload);
  return { items, parsed };
}

exports.main = main;
