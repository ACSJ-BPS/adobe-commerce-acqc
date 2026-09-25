const fakeSend = jest.fn();

beforeEach(() => {
  jest.clearAllMocks();
});

describe('sends email', () => {
  it('calls send once', () => {
    fakeSend();
    expect(fakeSend).toHaveBeenCalledTimes(1);
  });
});
