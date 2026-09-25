function handle (raw: string) {
  const data = JSON.parse(raw)
  const total: number = data.total
  data.process()
  const id = data.user.id
  return { total, id }
}
