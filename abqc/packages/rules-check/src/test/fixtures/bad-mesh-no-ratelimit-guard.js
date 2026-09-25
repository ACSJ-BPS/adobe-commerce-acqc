export const resolvers = {
  Query: {
    products: {
      resolve: async (root, args) => fetch(`/products?size=${args.pageSize}`),
    },
  },
};
