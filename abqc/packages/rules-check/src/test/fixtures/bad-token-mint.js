exports.main = async function main(params) {
  const token = jwt.sign({}, secret);
  return { token };
};
