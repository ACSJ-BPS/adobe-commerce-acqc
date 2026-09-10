async function main(params) {
  let hasMore = true;
  while (hasMore) {
    const lastId = (await state.get("export:lastId")) ?? 0;
    const batch = await fetchOrdersAfter(lastId, 500);
    hasMore = batch.length > 0;
    await processChunk(batch);
    await state.put("export:lastId", batch[batch.length - 1]?.id ?? lastId);
  }
  return { statusCode: 200 };
}

exports.main = main;
