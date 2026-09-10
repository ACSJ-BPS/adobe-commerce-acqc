import { createContext, useContext } from 'react';

const UserContext = createContext(null);

function App() {
  const user = useCurrentUser();
  return (
    <UserContext.Provider value={user}>
      <Layout />
    </UserContext.Provider>
  );
}

function UserAvatar() {
  const user = useContext(UserContext);
  return <Avatar src={user.avatar} alt={user.name} />;
}
