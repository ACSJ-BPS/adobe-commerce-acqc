async function saveEuProfile(params) {
  const { Files } = require("@adobe/aio-sdk");
  const files = await Files.init();
  const euCustomerProfile = params.profile;
  await files.write(`customers/${params.customerId}/profile.json`, JSON.stringify(euCustomerProfile));
}

exports.saveEuProfile = saveEuProfile;
