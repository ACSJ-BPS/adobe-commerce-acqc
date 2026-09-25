function formatDate(date) {
  return date.toISOString();
}

function parseDate(str) {
  return new Date(str);
}

module.exports = { formatDate, parseDate };
