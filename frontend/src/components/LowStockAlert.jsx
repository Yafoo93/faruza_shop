import React, { useEffect, useState } from "react";
import api from "../services/api";

function LowStockAlert() {
  const [notifications, setNotifications] = useState(null);

  useEffect(() => {
    async function fetchAlerts() {
      try {
        const res = await api.get("/notifications/alerts");
        setNotifications(res.data);
      } catch (err) {
        setNotifications(null);
      }
    }

    fetchAlerts();
    const interval = setInterval(fetchAlerts, 60000);
    return () => clearInterval(interval);
  }, []);

  const counts = notifications?.counts || {};
  const totalAlerts = Object.values(counts).reduce((total, count) => total + Number(count || 0), 0);

  if (!notifications || totalAlerts === 0) return null;

  return (
    <section className="notification-strip">
      <div>
        <strong>{totalAlerts} inventory alert(s)</strong>
        <span>Low stock, out-of-stock, expiry, dead-stock, and suspicious stock changes.</span>
      </div>
      <div className="notification-counts">
        {counts.low_stock > 0 && <span>Low {counts.low_stock}</span>}
        {counts.out_of_stock > 0 && <span>Out {counts.out_of_stock}</span>}
        {counts.expiring_soon > 0 && <span>Expiry {counts.expiring_soon}</span>}
        {counts.dead_stock > 0 && <span>Dead {counts.dead_stock}</span>}
        {counts.suspicious_changes > 0 && <span>Review {counts.suspicious_changes}</span>}
      </div>
    </section>
  );
}

export default LowStockAlert;
