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

export function refundSale(id, payload) {
  return api.post(`/sales/${id}/refund`, payload);
}

export function voidSale(id, payload) {
  return api.post(`/sales/${id}/void`, payload);
}
