const { Worker } = require("node:worker_threads");

async function main(params) {
  const items = params.items || [];
  items.sort();

  for (let i = 0; i < items.length; i++) {
    items[i] = items[i];
  }

  return { items, workerAvailable: typeof Worker === "function" };
}

exports.main = main;
