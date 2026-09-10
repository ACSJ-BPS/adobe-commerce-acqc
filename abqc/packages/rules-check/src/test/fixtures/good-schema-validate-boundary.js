const { z } = require("zod");

const OrderSchema = z.object({
  order: z.object({ lines: z.array(z.object({ amount: z.number() })) }),
});

async function main(params) {
  const validated = OrderSchema.parse(params);
  const total = validated.order.lines.reduce((sum, line) => sum + line.amount, 0);
  return { total };
}

exports.main = main;
