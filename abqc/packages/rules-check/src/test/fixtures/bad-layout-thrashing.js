export function resizeAll(items) {
  items.forEach((item) => {
    const height = item.el.offsetHeight;
    item.el.style.height = `${height + 10}px`;
  });
}
