exports.main = async function main(params) {
  return actions.invoke({ name: params.actionName, blocking: true });
};
