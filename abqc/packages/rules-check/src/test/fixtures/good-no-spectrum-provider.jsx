import { Provider, defaultTheme } from '@adobe/react-spectrum';

function App() {
  return (
    <Provider theme={defaultTheme} colorScheme="light" locale="en-US">
      <Routes />
    </Provider>
  );
}
