import { useState } from 'react';

export function LocaleSwitcher() {
  const [locale, setLocale] = useState('en');
  return <select onChange={(e) => setLocale(e.target.value)} value={locale} />;
}
