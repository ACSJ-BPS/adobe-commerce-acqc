export const beforeAll = async (ctx) => {
  const token = await ims.getAccessToken(ctx.clientId, ctx.clientSecret);
  ctx.token = token;
};
