function App() {
  useEffect(() => {
    document.body.style.height = '100vh';
    window.parent.postMessage({ type: 'navigate', url: '/dashboard' }, '*');
  }, []);
  return <Routes />;
}
