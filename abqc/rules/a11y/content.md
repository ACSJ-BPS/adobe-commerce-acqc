# Accessibility — Text alternatives & static content

> Part of [Accessibility](./README.md).

## Every img has a meaningful alt — empty alt for decorative [error] [a11y]

`jsx-a11y/alt-text` enforces this. Missing `alt` makes screen readers announce the file path; meaningful alt for decorative images creates noise. Empty `alt=""` is the explicit "decorative" signal.

### Bad
```jsx
<img src="/logo.png" />
<img src="/divider.png" alt="divider image" />
```

### Good
```jsx
<img src="/logo.png" alt="Acme Corp" />
<img src="/divider.png" alt="" />
```

---

## Anchors must have href and meaningful text [warn] [a11y]

`jsx-a11y/anchor-is-valid` flags `<a>` with no `href`, `href="#"`, or `href="javascript:void(0)"`. Screen readers announce links by purpose — "click here" or "read more" tells the user nothing.

### Check
```yaml
type: builtin
id: vague-link-text
files: ["**/*.{jsx,tsx,js,mjs,cjs}"]
```
Note: this check covers only the "meaningful text" half of the rule (a denylist of vague link phrases). The structural "has href" half is enforced separately via ESLint's `jsx-a11y/anchor-is-valid`.

### Bad
```jsx
<a onClick={openModal}>click here</a>
<a href="#" onClick={openModal}>open</a>
```

### Good
```jsx
<Button variant="cta" onPress={openModal}>Open settings</Button>
<a href="/settings">Open settings</a>
```

---

## <html> must have a lang attribute [error] [a11y, i18n]

`jsx-a11y/html-has-lang`. Screen readers select the correct pronunciation engine based on `<html lang>`. Missing it means English text gets read in the user's default language and vice versa — comprehension drops to zero.

### Bad
```html
<html>
  <head>...</head>
  <body><App /></body>
</html>
```

### Good
```html
<html lang="en-US">
  <head>...</head>
  <body><App /></body>
</html>
```

---

## No redundant alt text — "image of", "picture of" [warn] [a11y, screen-reader]

`jsx-a11y/img-redundant-alt`. Screen readers already announce that an `<img>` is an image. `alt="image of a kitten"` becomes "image image of a kitten" — describe the content, not the medium.

### Bad
```jsx
<img src="/kitten.jpg" alt="image of a kitten" />
```

### Good
```jsx
<img src="/kitten.jpg" alt="Tabby kitten sleeping in a sunbeam" />
```
