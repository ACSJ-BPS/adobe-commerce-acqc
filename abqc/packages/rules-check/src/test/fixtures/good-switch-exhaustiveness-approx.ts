type Shape = { kind: 'circle'; radius: number } | { kind: 'square'; side: number };

function area(shape: Shape): number {
  switch (shape.kind) {
    case 'circle':
      return 3.14 * shape.radius * shape.radius;
    case 'square':
      return shape.side * shape.side;
    default:
      return 0;
  }
}
