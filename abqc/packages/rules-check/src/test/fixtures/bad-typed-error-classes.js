function validate(value) {
  if (!value) {
    throw new Error("value is required");
  }
  return value;
}

module.exports = { validate };
