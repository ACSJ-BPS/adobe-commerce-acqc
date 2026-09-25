function AppRoutes() {
  return (
    <Routes>
      <Route path="/orders" element={
        <ErrorBoundary fallback={<RouteError />}>
          <Orders />
        </ErrorBoundary>
      } />
    </Routes>
  );
}
