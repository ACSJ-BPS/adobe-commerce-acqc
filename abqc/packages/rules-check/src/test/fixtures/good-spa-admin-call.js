async function load() {
  const res = await fetch('/api/v1/web/myaction');
  return res.json();
}
