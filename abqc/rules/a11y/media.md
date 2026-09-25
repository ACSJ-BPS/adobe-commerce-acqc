# Accessibility — Media

> Part of [Accessibility](./README.md).

## Media elements need captions or controls [warn] [a11y, media]

`jsx-a11y/media-has-caption`. `<video>` and `<audio>` without `<track kind="captions">` exclude deaf and hard-of-hearing users. Even auto-generated captions in a `<track>` reference is better than none.

### Bad
```jsx
<video src="/intro.mp4" controls />
```

### Good
```jsx
<video src="/intro.mp4" controls>
  <track kind="captions" src="/intro-captions.vtt" srcLang="en" label="English" default />
</video>
```
