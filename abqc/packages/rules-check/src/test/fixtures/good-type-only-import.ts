import type { Order } from './orders.js'
function format (o: Order): string { return JSON.stringify(o) }
