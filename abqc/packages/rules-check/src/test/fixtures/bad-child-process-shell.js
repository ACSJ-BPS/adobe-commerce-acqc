const { exec, spawn } = require("node:child_process");

function run(userInput) {
  exec("ls " + userInput);
}

function runShell(cmd) {
  spawn(cmd, [], { shell: true });
}

module.exports = { run, runShell };
