const fs = require("node:fs");

function loadConfig(path, callback) {
  fs.readFile(path, "utf8", (err, data) => {
    callback(err, data);
  });
}

module.exports = { loadConfig };
