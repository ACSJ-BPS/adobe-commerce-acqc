import { AppShell } from '@quarry/react';

function MyApp({ nav, content }) {
  return <AppShell nav={nav}>{content}</AppShell>;
}
