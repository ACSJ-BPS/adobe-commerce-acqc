const { S3Client, PutObjectCommand } = require("@aws-sdk/client-s3");

async function saveEuProfile(params) {
  const euCustomerProfile = params.profile;
  const s3 = new S3Client({ region: "eu-central-1" });
  await s3.send(new PutObjectCommand({
    Bucket: "eu-customer-data",
    Key: `customers/${params.customerId}/profile.json`,
    Body: JSON.stringify(euCustomerProfile),
  }));
}

exports.saveEuProfile = saveEuProfile;
