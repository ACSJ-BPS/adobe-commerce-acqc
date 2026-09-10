import { useEffect } from 'react';

export function Ticker() {
  useEffect(() => {
    setInterval(() => console.log('tick'), 1000);
  }, []);
  return <div>Ticker</div>;
}
