function Note({ userHtml }) {
  return <div dangerouslySetInnerHTML={{ __html: userHtml }} />;
}
