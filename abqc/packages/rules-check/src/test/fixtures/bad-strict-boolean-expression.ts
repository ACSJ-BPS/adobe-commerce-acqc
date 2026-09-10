function greet (name: string | undefined) {
  if (name) return `Hello ${name}`
  return 'Hello stranger'
}
