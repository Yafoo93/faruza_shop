import React, { useEffect, useState } from "react";
import api from "../services/api";

function LowStockAlert() {
  const [lowStockProducts, setLowStockProducts] = useState([]);

  useEffect(() => {
    async function fetchLowStock() {
      const res = await api.get("/products");
      const lowStock = res.data.filter(p => p.stock_qty <= p.min_stock_threshold);
      setLowStockProducts(lowStock);
    }

    fetchLowStock();
    const interval = setInterval(fetchLowStock, 60000); // refresh every 1 min
    return () => clearInterval(interval);
  }, []);

  if (lowStockProducts.length === 0) return null;

  return (
    <div style={{ border: "1px solid red", padding: 10, margin: 20, background: "#ffe5e5" }}>
      <h3 style={{ color: "red" }}>Low Stock Alerts!</h3>
      <ul>
        {lowStockProducts.map(p => (
          <li key={p.id}>{p.name} — Stock: {p.stock_qty} (Threshold: {p.min_stock_threshold})</li>
        ))}
      </ul>
    </div>
  );
}

export default LowStockAlert;