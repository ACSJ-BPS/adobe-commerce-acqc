export const resolvers = {
  Query: {
    products: {
      resolve: async (root, args, ctx) => {
        const count = await state.get(`ratelimit:${ctx.tenantId}`);
        if (count && count.value > 1000) throw new Error("Rate limit exceeded");
        return fetch(`/products?size=${args.pageSize}`);
      },
    },
  },
};
