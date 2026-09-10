const { randomBytes } = require('node:crypto')
const sessionId = randomBytes(16).toString('hex')
