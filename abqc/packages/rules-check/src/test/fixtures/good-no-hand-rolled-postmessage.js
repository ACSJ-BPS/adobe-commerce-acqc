import { register } from "@adobe/uix-guest";

export async function connect() {
  const guestConnection = await register({
    id: "my-vendor.my-extension",
    methods: {},
  });
  return guestConnection;
}
