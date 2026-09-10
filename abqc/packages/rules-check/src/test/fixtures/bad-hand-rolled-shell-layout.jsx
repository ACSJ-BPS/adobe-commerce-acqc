function AppShell({ nav, content }) {
  return (
    <div className="app-shell">
      <aside role="navigation">{nav}</aside>
      <main role="main">{content}</main>
    </div>
  );
}
