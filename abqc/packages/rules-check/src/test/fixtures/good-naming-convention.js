class OrderProcessor {
  constructor() {
    this.count = 0;
  }
}

const MAX_RETRY_COUNT = 3;

function run(orderId) {
  return orderId;
}

module.exports = { OrderProcessor, MAX_RETRY_COUNT, run };
