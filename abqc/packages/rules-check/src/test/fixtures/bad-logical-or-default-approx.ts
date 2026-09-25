function greet(name: string | undefined): void {
  const label = name || 'Guest';
  console.log(label);
}
