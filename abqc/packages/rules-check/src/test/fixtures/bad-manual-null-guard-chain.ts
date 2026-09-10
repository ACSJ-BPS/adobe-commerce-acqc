function street(user: { address?: { street?: string } }): string | undefined {
  return user && user.address && user.address.street;
}
