const pMap = require("p-map");

async function main(params) {
  const { State } = require("@adobe/aio-sdk");
  const state = await State.init();
  await pMap(params.events, (ev) => state.put(ev.id, ev.payload), { concurrency: 10 });
  return { statusCode: 200 };
}

exports.main = main;
