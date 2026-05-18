import { describe, expect, it } from "vitest";
import { flag, opt, repeatOpt, stripScanAiFlags } from "./argv.js";

describe("stripScanAiFlags", () => {
  it("removes --ai-insights and --predictive", () => {
    expect(stripScanAiFlags(["scan", ".", "--ai-insights", "--format", "json"])).toEqual([
      "scan",
      ".",
      "--format",
      "json",
    ]);
    expect(stripScanAiFlags(["--predictive", "x"])).toEqual(["x"]);
  });
});

describe("opt", () => {
  it("omits undefined values", () => {
    expect(opt("format", undefined)).toEqual([]);
    expect(opt("format", "json")).toEqual(["--format", "json"]);
  });
});

describe("repeatOpt", () => {
  it("repeats flags", () => {
    expect(repeatOpt("exclude", ["a", "b"])).toEqual(["--exclude", "a", "--exclude", "b"]);
    expect(repeatOpt("exclude", undefined)).toEqual([]);
  });
});

describe("flag", () => {
  it("emits boolean flags", () => {
    expect(flag("dry-run", true)).toEqual(["--dry-run"]);
    expect(flag("dry-run", false)).toEqual([]);
  });
});
