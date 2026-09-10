class ValidationError extends Error {
  constructor(message, code) {
    super(message);
    this.code = code;
    this.status = 400;
  }
}

function validate(value) {
  if (!value) {
    throw new ValidationError("value is required", "VALUE_REQUIRED");
  }
  return value;
}

module.exports = { validate, ValidationError };
