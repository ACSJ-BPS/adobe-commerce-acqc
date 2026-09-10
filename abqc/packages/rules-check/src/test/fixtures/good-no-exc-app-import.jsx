import excApp from '@adobe/exc-app';

function App() {
  useEffect(() => {
    const runtime = excApp();
    runtime.on('ready', ({ imsProfile }) => {});
    runtime.done();
  }, []);
  return <Routes />;
}
