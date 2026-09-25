exports.main = async function main(params) {
  return actions.invoke({ name: 'orders/process', blocking: true });
};
