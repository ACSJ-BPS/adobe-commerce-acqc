async function main(params) {
  const callerOrg = params.__ow_headers ? params.__ow_headers['x-gw-ims-org-id'] : undefined;
  if (!callerOrg) {
    return { statusCode: 401, body: { error: 'missing org context' } };
  }
  const order = await db.getOrder(params.orderId);
  if (!order || order.imsOrgId !== callerOrg) {
    return { statusCode: 403, body: { error: 'access denied' } };
  }
  return { statusCode: 200, body: order };
}
exports.main = main;
