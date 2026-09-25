const express = require("express");

process.on("unhandledRejection", (reason) => {
  console.error("unhandled rejection", reason);
});

process.on("uncaughtException", (err) => {
  console.error("uncaught exception", err);
  process.exit(1);
});

const app = express();

app.listen(3000, () => {
  console.log("listening");
});
