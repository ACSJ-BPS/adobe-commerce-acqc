class ErrorBoundary extends React.Component {
  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error, info) {
    logError(error, info);
  }

  render() {
    return this.state.hasError ? <Fallback /> : this.props.children;
  }
}

function UserCard({ id }) {
  const [data] = useState(null);
  return data ? <View>{data.name}</View> : <Spinner />;
}
