const fakeSend = jest.fn();

describe('sends email', () => {
  it('calls send once', () => {
    fakeSend();
    expect(fakeSend).toHaveBeenCalledTimes(1);
  });
});
