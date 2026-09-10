async function main(params) {
  let hasMore = true;
  while (hasMore) {
    const batch = await fetchNextBatch();
    hasMore = batch.length > 0;
    await processChunk(batch);
  }
  return { statusCode: 200 };
}

exports.main = main;
