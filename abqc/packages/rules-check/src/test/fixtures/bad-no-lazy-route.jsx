import Reports from './pages/Reports';
import Admin from './pages/Admin';

function App() {
  return (
    <Routes>
      <Route path="/reports" element={<Reports />} />
      <Route path="/admin" element={<Admin />} />
    </Routes>
  );
}
