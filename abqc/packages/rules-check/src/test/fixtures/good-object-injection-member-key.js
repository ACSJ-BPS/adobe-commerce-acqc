const ALLOWED_KEYS = new Set(['name', 'email', 'tier'])
function setField (obj, params) {
  if (!ALLOWED_KEYS.has(params.key)) return
  obj[params.key] = params.value
}
