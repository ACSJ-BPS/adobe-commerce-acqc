export const beforeAll = async (ctx) => {
  ctx.token = await ims.mint();
  ctx.flags = await launchDarkly.getFlags();
};
