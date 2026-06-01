import React, { useEffect, useMemo, useState } from "react";
import api from "../services/api";

function money(value) {
  return Number(value || 0).toFixed(2);
}

function compactMoney(value) {
  const amount = Number(value || 0);

  if (amount >= 1000) {
    return `GHS ${(amount / 1000).toFixed(1)}k`;
  }

  return `GHS ${money(amount)}`;
}

function maxValue(rows, field) {
  return Math.max(...rows.map((row) => Number(row[field] || 0)), 1);
}

function Dashboard() {
  const [dashboard, setDashboard] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    fetchDashboard();
  }, []);

  async function fetchDashboard() {
    setIsLoading(true);
    setError("");

    try {
      const res = await api.get("/dashboard");
      setDashboard(res.data);
    } catch (err) {
      const message = err.response?.status === 403
        ? "Only admin users can access analytics."
        : "Could not load dashboard analytics. Check that the Laravel API is running.";
      setError(message);
    } finally {
      setIsLoading(false);
    }
  }

  const kpis = dashboard?.kpis || {};
  const monthlyMax = useMemo(
    () => maxValue(dashboard?.monthly_revenue || [], "sales"),
    [dashboard]
  );
  const topSellerMax = useMemo(
    () => maxValue(dashboard?.top_selling_products || [], "quantity"),
    [dashboard]
  );
  const peakHourMax = useMemo(
    () => maxValue(dashboard?.peak_hours || [], "transactions"),
    [dashboard]
  );

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Owner analytics</p>
          <h2>Dashboard</h2>
          <p className="muted">Track sales, profit, stock risk, product movement, and cashier performance.</p>
        </div>
        <button className="ghost-button" disabled={isLoading} onClick={fetchDashboard} type="button">
          Refresh
        </button>
      </section>

      {error && <p className="form-error">{error}</p>}

      {isLoading ? (
        <p className="empty-state">Loading dashboard...</p>
      ) : dashboard && (
        <>
          <section className="stat-grid dashboard-kpis">
            <article className="stat-card">
              <span>Today's sales</span>
              <strong>GHS {money(kpis.today_sales)}</strong>
            </article>
            <article className="stat-card">
              <span>Today's profit</span>
              <strong>GHS {money(kpis.today_profit)}</strong>
            </article>
            <article className="stat-card">
              <span>Total profit</span>
              <strong>GHS {money(kpis.total_profit)}</strong>
            </article>
            <article className="stat-card warning">
              <span>Refunded</span>
              <strong>GHS {money(kpis.refunded_amount)}</strong>
            </article>
            <article className="stat-card warning">
              <span>Customer debt</span>
              <strong>GHS {money(kpis.outstanding_credit)}</strong>
            </article>
            <article className="stat-card warning">
              <span>Owing customers</span>
              <strong>{kpis.owing_customers || 0}</strong>
            </article>
            <article className="stat-card warning">
              <span>Voided sales</span>
              <strong>{kpis.voided_transactions || 0}</strong>
            </article>
            <article className="stat-card">
              <span>Stock value</span>
              <strong>GHS {money(kpis.stock_value)}</strong>
            </article>
            <article className="stat-card">
              <span>Transactions today</span>
              <strong>{kpis.today_transactions || 0}</strong>
            </article>
            <article className="stat-card warning">
              <span>Low stock</span>
              <strong>{kpis.low_stock_count || 0}</strong>
            </article>
            <article className="stat-card warning">
              <span>Expiring soon</span>
              <strong>{kpis.expiring_soon_count || 0}</strong>
            </article>
            <article className="stat-card warning">
              <span>Out of stock</span>
              <strong>{kpis.out_of_stock_count || 0}</strong>
            </article>
          </section>

          <section className="dashboard-grid">
            <article className="panel dashboard-panel wide">
              <div className="panel-heading">
                <div>
                  <h3>Monthly sales trend</h3>
                  <p className="muted">Net sales and estimated profit for the last 6 months.</p>
                </div>
              </div>
              <div className="bar-chart">
                {dashboard.monthly_revenue.map((row) => (
                  <div className="bar-column" key={row.month}>
                    <div className="bar-track">
                      <span style={{ height: `${Math.max((row.sales / monthlyMax) * 100, 3)}%` }} />
                    </div>
                    <strong>{compactMoney(row.sales)}</strong>
                    <small>{row.month}</small>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Recent transactions</h3>
                  <p className="muted">Latest sales, refunds, and voids.</p>
                </div>
              </div>
              <div className="compact-list">
                {dashboard.recent_transactions.length === 0 ? (
                  <p className="empty-state">No recent sales.</p>
                ) : dashboard.recent_transactions.map((sale) => (
                  <div className="compact-list-row" key={sale.id}>
                    <span>
                      <strong>Sale #{sale.id}</strong>
                      <small>{sale.status} - {sale.cashier} - {new Date(sale.created_at).toLocaleString()}</small>
                    </span>
                    <strong>GHS {money(sale.total)}</strong>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Top-selling products</h3>
                  <p className="muted">Products moving fastest by units sold.</p>
                </div>
              </div>
              <div className="ranked-list">
                {dashboard.top_selling_products.length === 0 ? (
                  <p className="empty-state">No product sales yet.</p>
                ) : dashboard.top_selling_products.map((product) => (
                  <div className="ranked-row" key={product.product_id}>
                    <div>
                      <strong>{product.name}</strong>
                      <span>{product.quantity} unit(s) - GHS {money(product.sales)}</span>
                    </div>
                    <div className="rank-bar">
                      <span style={{ width: `${Math.max((product.quantity / topSellerMax) * 100, 4)}%` }} />
                    </div>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Most profitable products</h3>
                  <p className="muted">Estimated profit from recorded sales.</p>
                </div>
              </div>
              <div className="compact-list">
                {dashboard.top_profit_products.length === 0 ? (
                  <p className="empty-state">No profit data yet.</p>
                ) : dashboard.top_profit_products.map((product) => (
                  <div className="compact-list-row" key={product.product_id}>
                    <span>
                      <strong>{product.name}</strong>
                      <small>{product.quantity} unit(s) sold</small>
                    </span>
                    <strong>GHS {money(product.profit)}</strong>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Cashier performance</h3>
                  <p className="muted">Who sells most by net sales.</p>
                </div>
              </div>
              <div className="compact-list">
                {dashboard.cashier_performance.length === 0 ? (
                  <p className="empty-state">No cashier sales yet.</p>
                ) : dashboard.cashier_performance.map((cashier) => (
                  <div className="compact-list-row" key={cashier.cashier_id || cashier.name}>
                    <span>
                      <strong>{cashier.name}</strong>
                      <small>{cashier.transactions} transaction(s)</small>
                    </span>
                    <strong>GHS {money(cashier.sales)}</strong>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Peak sales time</h3>
                  <p className="muted">Transactions grouped by hour.</p>
                </div>
              </div>
              <div className="ranked-list">
                {dashboard.peak_hours.length === 0 ? (
                  <p className="empty-state">No hourly sales yet.</p>
                ) : dashboard.peak_hours.map((hour) => (
                  <div className="ranked-row" key={hour.hour}>
                    <div>
                      <strong>{hour.hour}</strong>
                      <span>{hour.transactions} transaction(s)</span>
                    </div>
                    <div className="rank-bar">
                      <span style={{ width: `${Math.max((hour.transactions / peakHourMax) * 100, 4)}%` }} />
                    </div>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Low stock products</h3>
                  <p className="muted">Products at or below threshold.</p>
                </div>
              </div>
              <div className="compact-list">
                {dashboard.low_stock_products.length === 0 ? (
                  <p className="empty-state">No low stock products.</p>
                ) : dashboard.low_stock_products.map((product) => (
                  <div className="compact-list-row" key={product.id}>
                    <span>
                      <strong>{product.name}</strong>
                      <small>{product.sku} - threshold {product.min_stock_threshold}</small>
                    </span>
                    <strong>{product.stock_qty}</strong>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Rare movers</h3>
                  <p className="muted">No sale in the last 60 days.</p>
                </div>
              </div>
              <div className="compact-list">
                {dashboard.rare_movers.length === 0 ? (
                  <p className="empty-state">No slow-moving products.</p>
                ) : dashboard.rare_movers.map((product) => (
                  <div className="compact-list-row" key={product.id}>
                    <span>
                      <strong>{product.name}</strong>
                      <small>{product.last_sold_at ? `Last sold ${new Date(product.last_sold_at).toLocaleDateString()}` : "Never sold"}</small>
                    </span>
                    <strong>{product.stock_qty}</strong>
                  </div>
                ))}
              </div>
            </article>

            <article className="panel dashboard-panel">
              <div className="panel-heading">
                <div>
                  <h3>Expiry alerts</h3>
                  <p className="muted">Products expiring within 30 days.</p>
                </div>
              </div>
              <div className="compact-list">
                {dashboard.expiring_products.length === 0 ? (
                  <p className="empty-state">No products expiring soon.</p>
                ) : dashboard.expiring_products.map((product) => (
                  <div className="compact-list-row" key={product.id}>
                    <span>
                      <strong>{product.name}</strong>
                      <small>{product.sku} - {product.stock_qty} in stock</small>
                    </span>
                    <strong>{product.expiry_date}</strong>
                  </div>
                ))}
              </div>
            </article>
          </section>
        </>
      )}
    </div>
  );
}

export default Dashboard;
