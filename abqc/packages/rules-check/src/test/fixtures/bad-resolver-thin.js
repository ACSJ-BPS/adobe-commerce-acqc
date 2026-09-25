function calculateDiscount(root, args, context) {
  let discount = 0;
  if (args.customerType === "vip") {
    discount = 0.2;
  } else if (args.customerType === "gold") {
    discount = 0.15;
  } else if (args.customerType === "silver") {
    discount = 0.1;
  } else if (args.customerType === "bronze") {
    discount = 0.05;
  } else if (args.total > 1000) {
    discount = 0.08;
  } else if (args.total > 500) {
    discount = 0.04;
  }
  if (args.couponCode === "SAVE10") {
    discount += 0.1;
  }
  return args.total * (1 - discount);
}
module.exports = { calculateDiscount };
