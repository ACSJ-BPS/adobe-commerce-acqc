import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { join } from "node:path";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { EslintRunner } from "@abqc/core";

/**
 * Confirms each of these ESLint-plugin rules is actually WIRED and fires
 * through our EslintRunner (correct rule id, correct file-extension gating,
 * correct env/parser config) — not re-testing the plugin's own upstream
 * correctness. These specific rules had no organic case in either of the two
 * real App Builder samples used for the wider audit and no prior fixture in
 * this repo, so their wiring was unproven; each case here supplies a minimal
 * violating (and, where meaningful, compliant) snippet.
 */

async function withTempFile(ext: string, content: string, run: (filePath: string) => Promise<void>): Promise<void> {
  const root = await mkdtemp(join(tmpdir(), "abqc-eslint-wiring-"));
  const filePath = join(root, `case${ext}`);
  await writeFile(filePath, content, "utf8");
  try {
    await run(filePath);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
}

async function ruleIdsFor(filePath: string): Promise<string[]> {
  const runner = new EslintRunner({});
  return (await runner.run(filePath)).map((f) => f.ruleId);
}

describe("ESLint rule wiring — rules with no organic case in either audited sample", () => {
  it("no-implied-eval flags setTimeout with a string body", async () => {
    await withTempFile(".js", 'setTimeout("doSomething()", 100);\n', async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("no-implied-eval"));
    });
    await withTempFile(".js", "setTimeout(() => doSomething(), 100);\n", async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("no-implied-eval"));
    });
  });

  it("no-new-func flags the Function constructor", async () => {
    await withTempFile(".js", 'const fn = new Function("return 1;");\n', async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("no-new-func"));
    });
    await withTempFile(".js", "const fn = () => 1;\n", async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("no-new-func"));
    });
  });

  it("no-var flags var, prefers let/const", async () => {
    await withTempFile(".js", "var x = 1;\nconsole.log(x);\n", async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("no-var"));
    });
    await withTempFile(".js", "const x = 1;\nconsole.log(x);\n", async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("no-var"));
    });
  });

  it("no-throw-literal flags throwing a non-Error value", async () => {
    await withTempFile(".js", 'function f() { throw "boom"; }\nf();\n', async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("no-throw-literal"));
    });
    await withTempFile(".js", 'function f() { throw new Error("boom"); }\nf();\n', async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("no-throw-literal"));
    });
  });

  it("no-empty flags an empty block", async () => {
    await withTempFile(".js", "function f(x) {\n  if (x) {\n  }\n}\nf(1);\n", async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("no-empty"));
    });
    await withTempFile(".js", "function f(x) {\n  if (x) {\n    console.log(x);\n  }\n}\nf(1);\n", async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("no-empty"));
    });
  });

  it("no-cond-assign flags assignment inside a condition", async () => {
    await withTempFile(".js", "let y;\nfunction f(x) {\n  if (y = x) {\n    console.log(y);\n  }\n}\nf(1);\n", async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("no-cond-assign"));
    });
    await withTempFile(".js", "let y;\nfunction f(x) {\n  if (x === 1) {\n    y = x;\n    console.log(y);\n  }\n}\nf(1);\n", async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("no-cond-assign"));
    });
  });

  it("security/detect-non-literal-require flags a dynamic require() argument", async () => {
    await withTempFile(".js", "function load(moduleName) {\n  return require(moduleName);\n}\nload(\"./config\");\n", async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("security/detect-non-literal-require"));
    });
    await withTempFile(".js", 'const config = require("./config");\nconsole.log(config);\n', async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("security/detect-non-literal-require"));
    });
  });

  it("security/detect-possible-timing-attacks flags a direct secret comparison in an if-test", async () => {
    await withTempFile(
      ".js",
      "function check(password) {\n  if (password === 'expected-secret') {\n    return true;\n  }\n  return false;\n}\ncheck(\"a\");\n",
      async (f) => {
        assert.ok((await ruleIdsFor(f)).includes("security/detect-possible-timing-attacks"));
      },
    );
    await withTempFile(
      ".js",
      "function check(age) {\n  if (age === 5) {\n    return true;\n  }\n  return false;\n}\ncheck(1);\n",
      async (f) => {
        assert.ok(!(await ruleIdsFor(f)).includes("security/detect-possible-timing-attacks"));
      },
    );
  });

  it("security/detect-unsafe-regex flags a catastrophic-backtracking pattern", async () => {
    await withTempFile(".js", "const re = /(a+)+b/;\nconsole.log(re);\n", async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("security/detect-unsafe-regex"));
    });
    await withTempFile(".js", "const re = /ab/;\nconsole.log(re);\n", async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("security/detect-unsafe-regex"));
    });
  });

  it("import/no-self-import flags a module requiring itself", async () => {
    const root = await mkdtemp(join(tmpdir(), "abqc-eslint-wiring-"));
    const filePath = join(root, "self.js");
    await writeFile(filePath, "const self = require('./self.js');\nconsole.log(self);\n", "utf8");
    try {
      assert.ok((await ruleIdsFor(filePath)).includes("import/no-self-import"));
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });

  it("promise/no-return-wrap flags returning a wrapped Promise from .then()", async () => {
    await withTempFile(
      ".js",
      "function run(p, x) {\n  return p.then(function () {\n    return Promise.resolve(x);\n  });\n}\nrun(Promise.resolve(), 1);\n",
      async (f) => {
        assert.ok((await ruleIdsFor(f)).includes("promise/no-return-wrap"));
      },
    );
    await withTempFile(
      ".js",
      "function run(p, x) {\n  return p.then(function () {\n    return x;\n  });\n}\nrun(Promise.resolve(), 1);\n",
      async (f) => {
        assert.ok(!(await ruleIdsFor(f)).includes("promise/no-return-wrap"));
      },
    );
  });

  it("@typescript-eslint/no-explicit-any flags an explicit any-typed parameter", async () => {
    await withTempFile(".ts", "function f(x: any): void {\n  console.log(x);\n}\nf(1);\n", async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("@typescript-eslint/no-explicit-any"));
    });
    await withTempFile(".ts", "function f(x: number): void {\n  console.log(x);\n}\nf(1);\n", async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("@typescript-eslint/no-explicit-any"));
    });
  });

  it("@typescript-eslint/consistent-type-imports flags a type-only import without the `type` modifier", async () => {
    await withTempFile(
      ".ts",
      "import { Foo } from './foo';\nexport function use(x: Foo): void {\n  console.log(x);\n}\n",
      async (f) => {
        assert.ok((await ruleIdsFor(f)).includes("@typescript-eslint/consistent-type-imports"));
      },
    );
    await withTempFile(
      ".ts",
      "import type { Foo } from './foo';\nexport function use(x: Foo): void {\n  console.log(x);\n}\n",
      async (f) => {
        assert.ok(!(await ruleIdsFor(f)).includes("@typescript-eslint/consistent-type-imports"));
      },
    );
  });

  it("react/jsx-key flags a mapped JSX element with no key prop", async () => {
    await withTempFile(
      ".jsx",
      "function List({ items }) {\n  return items.map(function (x) {\n    return <li>{x}</li>;\n  });\n}\n",
      async (f) => {
        assert.ok((await ruleIdsFor(f)).includes("react/jsx-key"));
      },
    );
    await withTempFile(
      ".jsx",
      "function List({ items }) {\n  return items.map(function (x) {\n    return <li key={x}>{x}</li>;\n  });\n}\n",
      async (f) => {
        assert.ok(!(await ruleIdsFor(f)).includes("react/jsx-key"));
      },
    );
  });

  it("react/no-deprecated flags componentWillMount on a class component", async () => {
    await withTempFile(
      ".jsx",
      "class Foo extends React.Component {\n  componentWillMount() {}\n  render() {\n    return null;\n  }\n}\n",
      async (f) => {
        assert.ok((await ruleIdsFor(f)).includes("react/no-deprecated"));
      },
    );
    await withTempFile(
      ".jsx",
      "class Foo extends React.Component {\n  componentDidMount() {}\n  render() {\n    return null;\n  }\n}\n",
      async (f) => {
        assert.ok(!(await ruleIdsFor(f)).includes("react/no-deprecated"));
      },
    );
  });

  it("react/no-direct-mutation-state flags this.state.x = ... outside the constructor", async () => {
    await withTempFile(
      ".jsx",
      "class Foo extends React.Component {\n  componentDidMount() {\n    this.state.value = 1;\n  }\n  render() {\n    return null;\n  }\n}\n",
      async (f) => {
        assert.ok((await ruleIdsFor(f)).includes("react/no-direct-mutation-state"));
      },
    );
    await withTempFile(
      ".jsx",
      "class Foo extends React.Component {\n  componentDidMount() {\n    this.setState({ value: 1 });\n  }\n  render() {\n    return null;\n  }\n}\n",
      async (f) => {
        assert.ok(!(await ruleIdsFor(f)).includes("react/no-direct-mutation-state"));
      },
    );
  });

  it("jsx-a11y/aria-proptypes flags a non-boolean value on a boolean ARIA prop", async () => {
    await withTempFile(".jsx", 'function C() {\n  return <div aria-hidden="yes" />;\n}\n', async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("jsx-a11y/aria-proptypes"));
    });
    await withTempFile(".jsx", 'function C() {\n  return <div aria-hidden="true" />;\n}\n', async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("jsx-a11y/aria-proptypes"));
    });
  });

  it("jsx-a11y/role-supports-aria-props flags an ARIA prop not supported by the element's role", async () => {
    await withTempFile(".jsx", 'function C() {\n  return <div role="img" aria-checked="true" />;\n}\n', async (f) => {
      assert.ok((await ruleIdsFor(f)).includes("jsx-a11y/role-supports-aria-props"));
    });
    await withTempFile(".jsx", 'function C() {\n  return <div role="checkbox" aria-checked="true" />;\n}\n', async (f) => {
      assert.ok(!(await ruleIdsFor(f)).includes("jsx-a11y/role-supports-aria-props"));
    });
  });
});
