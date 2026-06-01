import api from "./api";

export function checkoutSale(payload) {
  return api.post("/sales", payload);
}

export function getSales(params = {}) {
  return api.get("/sales", { params });
}

export function getSale(id) {
  return api.get(`/sales/${id}`);
}
