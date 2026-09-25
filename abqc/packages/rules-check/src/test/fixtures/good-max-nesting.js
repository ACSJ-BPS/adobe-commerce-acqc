function classify(o) {
  if (!o.a) return 0;
  if (!o.b) return 0;
  if (!o.c) return 0;
  return o.d ? 1 : 0;
}
