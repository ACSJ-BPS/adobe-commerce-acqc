function resolveTree(root, args, context, info) {
  checkDepth(info, 10);
  return buildTree(root, args);
}
module.exports = { resolveTree };
