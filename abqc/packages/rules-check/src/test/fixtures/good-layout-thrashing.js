export function resizeAll(items) {
  const heights = items.map((item) => item.el.offsetHeight);
  items.forEach((item, i) => {
    item.el.style.height = `${heights[i] + 10}px`;
  });
}
