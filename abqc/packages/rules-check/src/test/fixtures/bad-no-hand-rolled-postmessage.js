export function notifyHost(payload) {
  window.parent.postMessage(payload, "*");
}

window.addEventListener("message", (event) => {
  console.log("received", event.data);
});
