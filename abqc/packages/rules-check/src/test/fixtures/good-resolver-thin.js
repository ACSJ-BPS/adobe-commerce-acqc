function calculateDiscount(root, args, context) {
  return applyDiscountPolicy(args.customerType, args.total, args.couponCode);
}
module.exports = { calculateDiscount };
