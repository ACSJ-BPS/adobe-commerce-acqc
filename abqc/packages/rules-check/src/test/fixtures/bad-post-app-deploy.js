async function postDeploy ({ appConfig }) {
  await migrateDatabaseSchema()
  await seedProductionData()
}
module.exports = postDeploy
