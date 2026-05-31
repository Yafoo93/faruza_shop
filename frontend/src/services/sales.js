import api from "./api";

export function checkoutSale(payload) {
  return api.post("/sales", payload);
}
