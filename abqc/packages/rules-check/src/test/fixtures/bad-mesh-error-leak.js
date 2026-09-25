export const resolvers = {
  Query: {
    orders: {
      resolve: async (root, args) => {
        try {
          return await fetchUpstream(args);
        } catch (error) {
          throw new Error(`Upstream failed: ${error.message}`);
        }
      },
    },
  },
};
