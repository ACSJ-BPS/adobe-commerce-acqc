async function loginRedirect(clientId) {
  const verifier = generateCodeVerifier();
  const challenge = await sha256(verifier);
  window.location = `https://ims-na1.adobelogin.com/ims/authorize/v2?response_type=code&code_challenge=${challenge}&code_challenge_method=S256&client_id=${clientId}`;
}
