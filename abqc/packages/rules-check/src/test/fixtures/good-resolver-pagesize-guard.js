function listProducts(root, args, context) {
  if (args.pageSize > 100) {
    throw new Error("pageSize too large");
  }
  return fetchProducts(args.pageSize);
}
module.exports = { listProducts };
