const { execFile, spawn } = require("node:child_process");

function run(userInput) {
  execFile("ls", [userInput]);
}

function runShell(cmd) {
  spawn(cmd, [], { shell: false });
}

module.exports = { run, runShell };
