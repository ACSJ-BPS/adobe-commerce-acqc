function identify(x: string): string;
function identify(x: number): string;
function identify(x: any): string {
  return String(x);
}
