export const resolvers = {
  Query: {
    orders: {
      resolve: async (root, args, ctx) => {
        const start = Date.now();
        const result = await fetchOrders(args);
        console.log({
          correlationId: ctx.requestId,
          source: "Commerce",
          operation: "orders",
          latencyMs: Date.now() - start,
          status: 200,
        });
        return result;
      },
    },
  },
};
