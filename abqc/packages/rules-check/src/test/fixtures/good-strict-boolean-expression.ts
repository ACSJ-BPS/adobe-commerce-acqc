function greet (name: string | undefined) {
  if (name !== undefined && name.length > 0) return `Hello ${name}`
  return 'Hello stranger'
}
