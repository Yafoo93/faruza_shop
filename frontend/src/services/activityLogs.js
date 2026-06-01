import api from "./api";

export function getActivityLogs(params = {}) {
  return api.get("/activity-logs", { params });
}
