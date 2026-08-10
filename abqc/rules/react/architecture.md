# React — Architecture & State

> Part of [React](./README.md).

## Check Quarry for higher-order Experience Cloud layout components before building custom [warn] [architecture, dx]

Adobe's **Quarry** library provides React Spectrum-based Experience Cloud patterns — standard app-shell layouts, side panels, list/detail views, toolbar placements, and other DX-wide compositions. Building these from scratch duplicates work, introduces visual inconsistency with other Adobe products, and misses accessibility fixes already in Quarry. Before writing a custom layout or panel component, check `quarry.corp.adobe.com` for an existing solution. New code should favour Quarry → React Spectrum → React Aria in that order before reaching for raw HTML or DIY components. Source: DXTS and Unified Shell internal engineering guidelines.

### Bad
```jsx
// custom app-shell layout — duplicates Quarry's standard patterns
function AppShell ({ nav, content }) {
  return (
    <div className="app-shell">
      <aside className="nav-panel">{nav}</aside>
      <main className="content-area">{content}</main>
    </div>
  )
}
```

### Good
```jsx
// check quarry.corp.adobe.com first; if an AppShell pattern exists, use it
import { AppShell } from '@quarry/react'

function MyApp () {
  return <AppShell nav={<MyNav />}><MyContent /></AppShell>
}
```

---

## Avoid prop drilling beyond two levels — use Context or a state library [warn] [architecture, state]

Passing props through three or more intermediate components that do not use them creates tight coupling, makes refactoring expensive, and signals a missing abstraction. For shared state that multiple components need, use React Context (with `useContext`) or a lightweight state library (Zustand, Jotai). Adobe internal teams use Context for auth/user state and locale, and a store library for complex UI state. Prop drilling is acceptable for one or two levels where the data is genuinely local; anything beyond that should be lifted to a context or store.

### Bad
```jsx
// App passes `user` through three components that don't use it
<App user={user}>
  <Layout user={user}>
    <Sidebar user={user}>
      <UserAvatar user={user} />  {/* only this component needs it */}
    </Sidebar>
  </Layout>
</App>
```

### Good
```jsx
// Hoist to context; any component can subscribe without prop chains
const UserContext = React.createContext(null)

function App () {
  const user = useCurrentUser()
  return <UserContext.Provider value={user}><Layout /></UserContext.Provider>
}

function UserAvatar () {
  const user = useContext(UserContext)
  return <Avatar src={user.avatar} alt={user.name} />
}
```
