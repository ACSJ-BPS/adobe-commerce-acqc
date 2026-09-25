function loginRedirect(clientId) {
  window.location = `https://ims-na1.adobelogin.com/ims/authorize/v2?response_type=token&client_id=${clientId}`;
}
