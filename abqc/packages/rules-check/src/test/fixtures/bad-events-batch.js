// I/O Events consumer (events-handler path) that makes a per-event
// synchronous HTTP call — must be flagged to batch via journaling.
exports.main = async function main(params) {
  const eventId = params.event_id;
  await fetch(`https://api.example.com/ingest/${eventId}`);
  return {};
};
