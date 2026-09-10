const fetch = require('node-fetch')

async function postDeploy ({ appConfig }) {
  const baseUrl = appConfig.app.actionUrl
  for (const action of ['health/ping', 'orders/list']) {
    const res = await fetch(`${baseUrl}/${action}`)
    if (!res.ok) throw new Error(`smoke check failed for ${action}: ${res.status}`)
  }
  await postSlackNotification('Deploy complete')
}
module.exports = postDeploy
