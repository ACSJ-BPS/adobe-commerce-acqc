const { pseudoRandomBytes } = require('node:crypto')
const sessionId = pseudoRandomBytes(16).toString('hex')
