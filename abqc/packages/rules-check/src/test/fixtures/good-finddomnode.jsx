function MyComponent() {
  const ref = useRef(null);
  useEffect(() => {
    ref.current?.focus();
  }, []);
  return <input ref={ref} />;
}
