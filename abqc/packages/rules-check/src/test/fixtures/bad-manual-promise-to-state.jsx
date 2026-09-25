function Resource({ promise }) {
  const [data, setData] = useState(null);
  useEffect(() => {
    promise.then(setData);
  }, [promise]);
  return data ? <View data={data} /> : <Spinner />;
}
