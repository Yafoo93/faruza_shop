import api from "./api";

export function getCustomers(params = {}) {
  return api.get("/customers", { params });
}

export function createCustomer(payload) {
  return api.post("/customers", payload);
}

export function updateCustomer(id, payload) {
  return api.put(`/customers/${id}`, payload);
}

export function getCustomer(id) {
  return api.get(`/customers/${id}`);
}

export function recordCustomerPayment(id, payload) {
  return api.post(`/customers/${id}/payment`, payload);
}
