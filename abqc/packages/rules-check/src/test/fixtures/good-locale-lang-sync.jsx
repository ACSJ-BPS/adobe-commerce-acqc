import { useState, useEffect } from 'react';

export function LocaleSwitcher() {
  const [locale, setLocale] = useState('en');
  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);
  return <select onChange={(e) => setLocale(e.target.value)} value={locale} />;
}
