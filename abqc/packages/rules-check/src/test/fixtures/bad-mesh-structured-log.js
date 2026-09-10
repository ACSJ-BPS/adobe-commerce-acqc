export const resolvers = {
  Query: {
    orders: {
      resolve: async (root, args, ctx) => {
        console.log("error");
        return fetchOrders(args);
      },
    },
  },
};
