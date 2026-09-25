export const beforeAll = async (ctx) => {
  const cached = await state.get("mesh-token");
  ctx.token = cached ? cached.value : await ims.mint();
};
