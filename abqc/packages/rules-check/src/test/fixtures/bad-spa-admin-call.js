async function load() {
  const res = await fetch('https://host/graphql');
  return res.json();
}
