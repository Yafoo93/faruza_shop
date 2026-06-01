import api from "./api";

export function getUsers(params = {}) {
  return api.get("/users", { params });
}

export function createUser(payload) {
  return api.post("/users", payload);
}

export function updateUser(id, payload) {
  return api.put(`/users/${id}`, payload);
}

export function disableUser(id) {
  return api.post(`/users/${id}/disable`);
}

export function enableUser(id) {
  return api.post(`/users/${id}/enable`);
}
