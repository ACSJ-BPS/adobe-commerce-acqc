import { use, Suspense } from 'react';

function Resource({ promise }) {
  return <View data={use(promise)} />;
}

function Page({ promise }) {
  return (
    <Suspense fallback={<Spinner />}>
      <Resource promise={promise} />
    </Suspense>
  );
}
