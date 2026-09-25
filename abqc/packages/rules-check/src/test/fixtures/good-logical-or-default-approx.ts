function greet(name: string | undefined): void {
  if (name || false) {
    console.log(name);
  }
}
