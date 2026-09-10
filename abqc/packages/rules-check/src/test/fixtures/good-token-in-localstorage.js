let accessToken = null;

function saveSession(token) {
  accessToken = token; // memory-only
}

function getSession() {
  return accessToken;
}
