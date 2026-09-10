function listProducts(root, args, context) {
  const pageSize = args.pageSize;
  return fetchProducts(pageSize);
}
module.exports = { listProducts };
