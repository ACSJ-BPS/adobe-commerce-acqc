export const beforeAll = async (ctx) => {
  const cached = await state.get("mesh-token");
  if (cached) {
    ctx.token = cached.value;
    return;
  }
  const token = await ims.getAccessToken(ctx.clientId, ctx.clientSecret);
  await state.put("mesh-token", token, { ttl: 3300 });
  ctx.token = token;
};
