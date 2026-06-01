import React, { useEffect, useMemo, useState } from "react";
import { getInventory } from "../services/inventory";

function money(value) {
  return Number(value || 0).toFixed(2);
}

function statusClass(status) {
  return String(status || "").toLowerCase().replaceAll(" ", "-");
}

function dateValue(value) {
  if (!value) return "None";
  return new Date(value).toLocaleDateString();
}

function Inventory() {
  const [inventory, setInventory] = useState(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    fetchInventory();
  }, []);

  async function fetchInventory() {
    setIsLoading(true);
    setError("");

    try {
      const res = await getInventory();
      setInventory(res.data);
    } catch (err) {
      const message = err.response?.status === 403
        ? "Only admin users can access inventory analytics."
        : "Could not load inventory. Check that the Laravel API is running.";
      setError(message);
    } finally {
      setIsLoading(false);
    }
  }

  const products = inventory?.products || [];
  const summary = inventory?.summary || {};

  const filteredProducts = useMemo(() => {
    const query = search.trim().toLowerCase();

    return products.filter((product) => {
      const matchesStatus = status === "all" || product.status === status;
      const matchesSearch =
        !query ||
        product.name.toLowerCase().includes(query) ||
        product.sku.toLowerCase().includes(query) ||
        product.category.toLowerCase().includes(query);

      return matchesStatus && matchesSearch;
    });
  }, [products, search, status]);

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Inventory</p>
          <h2>Stock control</h2>
          <p className="muted">Monitor stock value, risk alerts, expiry, dead stock, and recent restocks.</p>
        </div>
        <button className="ghost-button" disabled={isLoading} onClick={fetchInventory} type="button">
          Refresh
        </button>
      </section>

      {error && <p className="form-error">{error}</p>}

      {isLoading ? (
        <p className="empty-state">Loading inventory...</p>
      ) : inventory && (
        <>
          <section className="stat-grid dashboard-kpis">
            <article className="stat-card">
              <span>Products</span>
              <strong>{summary.products_count || 0}</strong>
            </article>
            <article className="stat-card">
              <span>Units in stock</span>
              <strong>{summary.units_in_stock || 0}</strong>
            </article>
            <article className="stat-card">
              <span>Stock value</span>
              <strong>GHS {money(summary.stock_value)}</strong>
            </article>
            <article className="stat-card">
              <span>Potential revenue</span>
              <strong>GHS {money(summary.potential_revenue)}</strong>
            </article>
            <article className="stat-card warning">
              <span>Low stock</span>
              <strong>{summary.low_stock_count || 0}</strong>
            </article>
            <article className="stat-card warning">
              <span>Out of stock</span>
              <strong>{summary.out_of_stock_count || 0}</strong>
            </article>
            <article className="stat-card warning">
              <span>Expiring soon</span>
              <strong>{summary.expiring_soon_count || 0}</strong>
            </article>
            <article className="stat-card warning">
              <span>Dead stock</span>
              <strong>{summary.dead_stock_count || 0}</strong>
            </article>
          </section>

          <section className="dashboard-grid">
            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Inventory alerts</h3>
                  <p className="muted">Items that need attention.</p>
                </div>
              </div>
              <div className="inventory-alert-grid">
                <div>
                  <strong>Low stock</strong>
                  <span>{inventory.alerts.low_stock.length}</span>
                </div>
                <div>
                  <strong>Out of stock</strong>
                  <span>{inventory.alerts.out_of_stock.length}</span>
                </div>
                <div>
                  <strong>Expiring soon</strong>
                  <span>{inventory.alerts.expiring_soon.length}</span>
                </div>
                <div>
                  <strong>No sales in 60 days</strong>
                  <span>{inventory.alerts.dead_stock.length}</span>
                </div>
              </div>
            </article>

            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Recent restocks</h3>
                  <p className="muted">Latest stock additions.</p>
                </div>
              </div>
              <div className="compact-list">
                {inventory.recent_restocks.length === 0 ? (
                  <p className="empty-state">No restock history yet.</p>
                ) : inventory.recent_restocks.map((row) => (
                  <div className="compact-list-row" key={row.id}>
                    <span>
                      <strong>{row.product?.name || "Unknown product"}</strong>
                      <small>{row.user?.name || "Unknown"} - {new Date(row.created_at).toLocaleString()}</small>
                    </span>
                    <strong>+{row.quantity_added}</strong>
                  </div>
                ))}
              </div>
            </article>
          </section>

          <section className="panel">
            <div className="table-toolbar">
              <div>
                <h3>Stock list</h3>
                <p className="muted">{filteredProducts.length} product(s) shown</p>
              </div>
              <div className="toolbar-controls">
                <input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Search name, SKU, category"
                />
                <select value={status} onChange={(event) => setStatus(event.target.value)}>
                  <option value="all">All statuses</option>
                  <option value="In Stock">In stock</option>
                  <option value="Low Stock">Low stock</option>
                  <option value="Critical Stock">Critical stock</option>
                  <option value="Out of Stock">Out of stock</option>
                </select>
              </div>
            </div>

            {filteredProducts.length === 0 ? (
              <p className="empty-state">No products found.</p>
            ) : (
              <div className="table-wrap">
                <table className="data-table inventory-table">
                  <thead>
                    <tr>
                      <th>Product</th>
                      <th>Category</th>
                      <th>Stock</th>
                      <th>Status</th>
                      <th>Value</th>
                      <th>Expiry</th>
                      <th>Last sold</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredProducts.map((product) => (
                      <tr key={product.id}>
                        <td>
                          <strong>{product.name}</strong>
                          <span className="subtext">{product.sku}</span>
                        </td>
                        <td>{product.category}</td>
                        <td>{product.stock_qty}</td>
                        <td>
                          <span className={`status-pill ${statusClass(product.status)}`}>
                            {product.status}
                          </span>
                        </td>
                        <td>GHS {money(product.stock_value)}</td>
                        <td>
                          {dateValue(product.expiry_date)}
                          {product.expires_soon && <span className="subtext danger-text">Expiring soon</span>}
                        </td>
                        <td>
                          {product.last_sold_at ? dateValue(product.last_sold_at) : "Never"}
                          {product.dead_stock && <span className="subtext warning-text">No sales in 60 days</span>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}
    </div>
  );
}

export default Inventory;
