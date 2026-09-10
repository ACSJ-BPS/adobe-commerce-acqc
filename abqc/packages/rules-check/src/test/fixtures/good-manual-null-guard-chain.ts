function street(user: { address?: { street?: string } }): string | undefined {
  return user?.address?.street;
}
