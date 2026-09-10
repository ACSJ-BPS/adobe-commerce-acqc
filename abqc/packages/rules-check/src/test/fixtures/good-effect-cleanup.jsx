import { useEffect } from 'react';

export function Ticker() {
  useEffect(() => {
    const id = setInterval(() => console.log('tick'), 1000);
    return () => clearInterval(id);
  }, []);
  return <div>Ticker</div>;
}
