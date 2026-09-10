class UserCard extends React.Component {
  state = { loading: true };

  render() {
    return this.state.loading ? <Spinner /> : <View>{this.state.data.name}</View>;
  }
}
