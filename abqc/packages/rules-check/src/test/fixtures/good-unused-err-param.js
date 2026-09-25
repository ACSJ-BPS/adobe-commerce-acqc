function onComplete(err, result) {
  if (err) {
    console.error(err);
    return;
  }
  console.log(result);
}

module.exports = { onComplete };
