async function main(params) {
  const { State } = require("@adobe/aio-sdk");
  const state = await State.init();
  await Promise.all(
    params.events.map((ev) => state.put(ev.id, ev.payload)),
  );
  return { statusCode: 200 };
}

exports.main = main;
