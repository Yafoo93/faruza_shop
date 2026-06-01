import api from "./api";

export function getInventory() {
  return api.get("/inventory");
}

export function getInventoryHistory(params = {}) {
  return api.get("/inventory/history", { params });
}
