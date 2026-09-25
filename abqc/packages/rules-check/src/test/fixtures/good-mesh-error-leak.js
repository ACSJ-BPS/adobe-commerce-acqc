export const resolvers = {
  Query: {
    orders: {
      resolve: async (root, args) => {
        try {
          return await fetchUpstream(args);
        } catch (error) {
          logger.error("upstream_failed", { code: error.code });
          throw new Error("UPSTREAM_UNAVAILABLE");
        }
      },
    },
  },
};
