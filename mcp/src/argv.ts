/** Strip forbidden AI-related flags from scan argv (defense in depth). */
export function stripScanAiFlags(argv: string[]): string[] {
  const out: string[] = [];
  for (const a of argv) {
    if (a === "--ai-insights" || a === "--predictive") {
      continue;
    }
    if (a.startsWith("--ai-insights=") || a.startsWith("--predictive=")) {
      continue;
    }
    out.push(a);
  }
  return out;
}

export function opt(name: string, value: string | number | undefined): string[] {
  if (value === undefined) {
    return [];
  }
  return [`--${name}`, String(value)];
}

export function flag(name: string, on?: boolean): string[] {
  if (!on) {
    return [];
  }
  return [`--${name}`];
}

export function repeatOpt(name: string, values: string[] | undefined): string[] {
  if (!values?.length) {
    return [];
  }
  const out: string[] = [];
  for (const v of values) {
    out.push(`--${name}`, v);
  }
  return out;
}
