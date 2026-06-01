import React, { useEffect, useMemo, useState } from "react";
import { downloadReceipt } from "../services/reports";
import { getSales, refundSale, voidSale } from "../services/sales";

function money(value) {
  return Number(value || 0).toFixed(2);
}

function paymentLabel(value) {
  return String(value || "")
    .replace("_", " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusLabel(value) {
  return String(value || "completed")
    .replace("_", " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function saleRows(response) {
  return response?.sales?.data || [];
}

function Sales({ user }) {
  const isAdmin = user?.role === "admin";
  const [sales, setSales] = useState([]);
  const [summary, setSummary] = useState({
    sales_count: 0,
    subtotal: 0,
    discount_amount: 0,
    total: 0,
    amount_paid: 0,
    credit_amount: 0,
  });
  const [pagination, setPagination] = useState(null);
  const [filters, setFilters] = useState({
    search: "",
    from: "",
    to: "",
    payment_method: "",
    status: "",
    payment_status: "",
  });
  const [expandedSaleId, setExpandedSaleId] = useState(null);
  const [refundForms, setRefundForms] = useState({});
  const [downloadingReceiptId, setDownloadingReceiptId] = useState(null);
  const [processingSaleId, setProcessingSaleId] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  useEffect(() => {
    fetchSales();
  }, []);

  async function fetchSales(page = 1) {
    setIsLoading(true);
    setError("");
    setMessage("");

    const params = Object.fromEntries(
      Object.entries({ ...filters, page }).filter(([, value]) => value !== "")
    );

    try {
      const res = await getSales(params);
      const paginator = res.data.sales;
      setSales(saleRows(res.data));
      setSummary(res.data.summary || summary);
      setPagination({
        current_page: paginator.current_page,
        last_page: paginator.last_page,
        from: paginator.from,
        to: paginator.to,
        total: paginator.total,
      });
    } catch (err) {
      setError("Could not load sales. Check that the Laravel API is running.");
    } finally {
      setIsLoading(false);
    }
  }

  function updateFilter(field, value) {
    setFilters((current) => ({ ...current, [field]: value }));
  }

  function applyFilters(event) {
    event.preventDefault();
    fetchSales(1);
  }

  function resetFilters() {
    const nextFilters = {
      search: "",
      from: "",
      to: "",
      payment_method: "",
      status: "",
      payment_status: "",
    };

    setFilters(nextFilters);
    setExpandedSaleId(null);
    setIsLoading(true);
    setError("");
    setMessage("");

    getSales({ page: 1 })
      .then((res) => {
        const paginator = res.data.sales;
        setSales(saleRows(res.data));
        setSummary(res.data.summary || summary);
        setPagination({
          current_page: paginator.current_page,
          last_page: paginator.last_page,
          from: paginator.from,
          to: paginator.to,
          total: paginator.total,
        });
      })
      .catch(() => {
        setError("Could not load sales. Check that the Laravel API is running.");
      })
      .finally(() => {
        setIsLoading(false);
      });
  }

  async function handleReceiptDownload(saleId, format) {
    setDownloadingReceiptId(saleId);
    setError("");

    try {
      await downloadReceipt(saleId, format);
    } catch (err) {
      setError("Could not download receipt.");
    } finally {
      setDownloadingReceiptId(null);
    }
  }

  function updateRefundForm(saleId, field, value) {
    setRefundForms((current) => ({
      ...current,
      [saleId]: {
        reason: "",
        quantities: {},
        ...(current[saleId] || {}),
        [field]: value,
      },
    }));
  }

  function updateRefundQuantity(saleId, itemId, value) {
    setRefundForms((current) => ({
      ...current,
      [saleId]: {
        reason: "",
        quantities: {},
        ...(current[saleId] || {}),
        quantities: {
          ...((current[saleId] || {}).quantities || {}),
          [itemId]: value,
        },
      },
    }));
  }

  async function handleRefund(sale) {
    const form = refundForms[sale.id] || {};
    const items = (sale.items || [])
      .map((item) => ({
        sale_item_id: item.id,
        quantity: Number(form.quantities?.[item.id] || 0),
      }))
      .filter((item) => item.quantity > 0);

    if (!form.reason?.trim() || items.length === 0) {
      setError("Enter a refund reason and at least one item quantity.");
      return;
    }

    setProcessingSaleId(sale.id);
    setError("");
    setMessage("");

    try {
      await refundSale(sale.id, { reason: form.reason, items });
      setMessage(`Refund processed for sale #${sale.id}.`);
      setRefundForms((current) => ({ ...current, [sale.id]: { reason: "", quantities: {} } }));
      await fetchSales(pagination?.current_page || 1);
      setExpandedSaleId(sale.id);
    } catch (err) {
      setError(err.response?.data?.message || "Could not process refund.");
    } finally {
      setProcessingSaleId(null);
    }
  }

  async function handleVoid(sale) {
    const reason = window.prompt(`Reason for voiding sale #${sale.id}`);
    if (!reason?.trim()) return;

    setProcessingSaleId(sale.id);
    setError("");
    setMessage("");

    try {
      await voidSale(sale.id, { reason });
      setMessage(`Sale #${sale.id} was voided.`);
      await fetchSales(pagination?.current_page || 1);
    } catch (err) {
      setError(err.response?.data?.message || "Could not void sale.");
    } finally {
      setProcessingSaleId(null);
    }
  }

  const pageLabel = useMemo(() => {
    if (!pagination || pagination.total === 0) return "No sales";
    return `${pagination.from || 0}-${pagination.to || 0} of ${pagination.total}`;
  }, [pagination]);

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Sales</p>
          <h2>Sales history</h2>
          <p className="muted">Review receipts, payment methods, discounts, and cashier activity.</p>
        </div>
      </section>

      <section className="stat-grid">
        <article className="stat-card">
          <span>Sales</span>
          <strong>{summary.sales_count}</strong>
        </article>
        {isAdmin && (
          <>
            <article className="stat-card">
              <span>Net sales</span>
              <strong>GHS {money(summary.total)}</strong>
            </article>
            <article className="stat-card">
              <span>Discounts</span>
              <strong>GHS {money(summary.discount_amount)}</strong>
            </article>
            <article className="stat-card">
              <span>Collected</span>
              <strong>GHS {money(summary.amount_paid)}</strong>
            </article>
            <article className="stat-card">
              <span>Refunded</span>
              <strong>GHS {money(summary.refunded_amount)}</strong>
            </article>
            <article className="stat-card warning">
              <span>Credit</span>
              <strong>GHS {money(summary.credit_amount)}</strong>
            </article>
          </>
        )}
      </section>

      <section className="panel">
        <form className="sales-filters" onSubmit={applyFilters}>
          <label>
            Search
            <input
              value={filters.search}
              onChange={(event) => updateFilter("search", event.target.value)}
              placeholder="Sale ID, product, SKU, cashier"
            />
          </label>
          <label>
            From
            <input
              type="date"
              value={filters.from}
              onChange={(event) => updateFilter("from", event.target.value)}
            />
          </label>
          <label>
            To
            <input
              type="date"
              value={filters.to}
              onChange={(event) => updateFilter("to", event.target.value)}
            />
          </label>
          <label>
            Payment
            <select
              value={filters.payment_method}
              onChange={(event) => updateFilter("payment_method", event.target.value)}
            >
              <option value="">All methods</option>
              <option value="cash">Cash</option>
              <option value="mobile_money">Mobile money</option>
              <option value="card">Card</option>
              <option value="credit">Credit</option>
            </select>
          </label>
          <label>
            Payment status
            <select
              value={filters.payment_status}
              onChange={(event) => updateFilter("payment_status", event.target.value)}
            >
              <option value="">All</option>
              <option value="paid">Paid</option>
              <option value="partial">Partial</option>
              <option value="unpaid">Unpaid</option>
            </select>
          </label>
          <label>
            Status
            <select
              value={filters.status}
              onChange={(event) => updateFilter("status", event.target.value)}
            >
              <option value="">All statuses</option>
              <option value="completed">Completed</option>
              <option value="partially_refunded">Partially refunded</option>
              <option value="refunded">Refunded</option>
              <option value="voided">Voided</option>
            </select>
          </label>
          <div className="filter-actions">
            <button className="primary-button" disabled={isLoading} type="submit">
              Apply
            </button>
            <button className="ghost-button" disabled={isLoading} onClick={resetFilters} type="button">
              Reset
            </button>
          </div>
        </form>
      </section>

      <section className="panel">
        <div className="panel-heading">
          <div>
            <h3>Receipts</h3>
            <p className="muted">{pageLabel}</p>
          </div>
          <button className="ghost-button" disabled={isLoading} onClick={() => fetchSales()} type="button">
            Refresh
          </button>
        </div>

        {error && <p className="form-error">{error}</p>}
        {message && <p className="form-success">{message}</p>}

        {isLoading ? (
          <p className="empty-state">Loading sales...</p>
        ) : sales.length === 0 ? (
          <p className="empty-state">No sales found.</p>
        ) : (
          <div className="table-wrap">
            <table className="data-table sales-table">
              <thead>
                <tr>
                  <th>Sale</th>
                  <th>Date</th>
                  <th>Cashier</th>
                  <th>Customer</th>
                  <th>Items</th>
                  <th>Payment</th>
                  <th>Total</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {sales.map((sale) => (
                  <React.Fragment key={sale.id}>
                    <tr>
                      <td>
                        <strong>#{sale.id}</strong>
                        <span className={`status-pill ${sale.status === "voided" ? "out-of-stock" : sale.status === "completed" ? "in-stock" : "low-stock"}`}>
                          {statusLabel(sale.status)}
                        </span>
                        {sale.notes && <span className="subtext">{sale.notes}</span>}
                      </td>
                      <td>{new Date(sale.created_at).toLocaleString()}</td>
                      <td>{sale.cashier?.name || "Unknown"}</td>
                      <td>
                        {sale.customer_name || sale.customer?.name || "Walk-in"}
                        {sale.customer_phone && <span className="subtext">{sale.customer_phone}</span>}
                      </td>
                      <td>{sale.total_quantity} unit(s)</td>
                      <td>
                        <span className="status-pill in-stock">{paymentLabel(sale.payment_method)}</span>
                      </td>
                      <td>
                        <strong>GHS {money(sale.net_total ?? sale.total)}</strong>
                        {Number(sale.discount_amount || 0) > 0 && (
                          <span className="subtext">Discount GHS {money(sale.discount_amount)}</span>
                        )}
                        {Number(sale.refunded_amount || 0) > 0 && (
                          <span className="subtext">Refunded GHS {money(sale.refunded_amount)}</span>
                        )}
                        {Number(sale.credit_amount || 0) > 0 && (
                          <span className="subtext warning-text">Credit GHS {money(sale.credit_amount)}</span>
                        )}
                      </td>
                      <td>
                        <div className="row-actions wrap">
                          <button
                            className="ghost-button"
                            onClick={() =>
                              setExpandedSaleId((current) => (current === sale.id ? null : sale.id))
                            }
                            type="button"
                          >
                            {expandedSaleId === sale.id ? "Hide" : "View"}
                          </button>
                          <button
                            className="ghost-button"
                            disabled={downloadingReceiptId === sale.id}
                            onClick={() => handleReceiptDownload(sale.id, "pdf")}
                            type="button"
                          >
                            PDF
                          </button>
                          <button
                            className="ghost-button"
                            disabled={downloadingReceiptId === sale.id}
                            onClick={() => handleReceiptDownload(sale.id, "excel")}
                            type="button"
                          >
                            Excel
                          </button>
                          <button
                            className="ghost-button"
                            disabled={downloadingReceiptId === sale.id}
                            onClick={() => handleReceiptDownload(sale.id, "csv")}
                            type="button"
                          >
                            CSV
                          </button>
                          {isAdmin && sale.status !== "voided" && sale.status !== "refunded" && (
                            <button
                              className="danger-button"
                              disabled={processingSaleId === sale.id || Number(sale.refunded_amount || 0) > 0}
                              onClick={() => handleVoid(sale)}
                              type="button"
                            >
                              Void
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                    {expandedSaleId === sale.id && (
                      <tr className="sale-detail-row">
                        <td colSpan="8">
                          <div className="sale-detail">
                            <div className="receipt-lines">
                              {sale.items?.map((item) => (
                                <div className="receipt-line" key={item.id}>
                                  <span>
                                    <strong>{item.product_name}</strong>
                                    <small>
                                      {item.product_sku} - {item.quantity} x GHS {money(item.unit_price)}
                                    </small>
                                    {Number(item.refunded_quantity || 0) > 0 && (
                                      <small>Refunded {item.refunded_quantity}; net {Math.max(Number(item.quantity || 0) - Number(item.refunded_quantity || 0), 0)}</small>
                                    )}
                                  </span>
                                  <strong>GHS {money(item.line_total)}</strong>
                                </div>
                              ))}
                            </div>
                            <div className="receipt-totals">
                              <div>
                                <span>Subtotal</span>
                                <strong>GHS {money(sale.subtotal)}</strong>
                              </div>
                              <div>
                                <span>Discount</span>
                                <strong>GHS {money(sale.discount_amount)}</strong>
                              </div>
                              <div>
                                <span>Refunded</span>
                                <strong>GHS {money(sale.refunded_amount)}</strong>
                              </div>
                              <div>
                                <span>Net total</span>
                                <strong>GHS {money(sale.net_total ?? sale.total)}</strong>
                              </div>
                              <div>
                                <span>Paid</span>
                                <strong>GHS {money(sale.amount_paid)}</strong>
                              </div>
                              <div>
                                <span>Credit</span>
                                <strong>GHS {money(sale.credit_amount)}</strong>
                              </div>
                              <div>
                                <span>Change</span>
                                <strong>GHS {money(sale.change_due)}</strong>
                              </div>
                            </div>
                            {isAdmin && sale.status !== "voided" && sale.status !== "refunded" && (
                              <div className="refund-panel">
                                <h4>Refund items</h4>
                                <div className="refund-grid">
                                  {sale.items?.map((item) => {
                                    const available = Math.max(Number(item.quantity || 0) - Number(item.refunded_quantity || 0), 0);
                                    return (
                                      <label key={item.id}>
                                        {item.product_name}
                                        <input
                                          disabled={available <= 0 || processingSaleId === sale.id}
                                          max={available}
                                          min="0"
                                          onChange={(event) => updateRefundQuantity(sale.id, item.id, event.target.value)}
                                          placeholder={`0 of ${available}`}
                                          type="number"
                                          value={refundForms[sale.id]?.quantities?.[item.id] || ""}
                                        />
                                      </label>
                                    );
                                  })}
                                </div>
                                <label>
                                  Reason
                                  <textarea
                                    disabled={processingSaleId === sale.id}
                                    onChange={(event) => updateRefundForm(sale.id, "reason", event.target.value)}
                                    placeholder="Reason for return or refund"
                                    rows="2"
                                    value={refundForms[sale.id]?.reason || ""}
                                  />
                                </label>
                                <button
                                  className="primary-button"
                                  disabled={processingSaleId === sale.id}
                                  onClick={() => handleRefund(sale)}
                                  type="button"
                                >
                                  {processingSaleId === sale.id ? "Processing..." : "Process refund"}
                                </button>
                              </div>
                            )}
                          </div>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {pagination && pagination.last_page > 1 && (
          <div className="pagination-actions">
            <button
              className="ghost-button"
              disabled={isLoading || pagination.current_page <= 1}
              onClick={() => fetchSales(pagination.current_page - 1)}
              type="button"
            >
              Previous
            </button>
            <span>
              Page {pagination.current_page} of {pagination.last_page}
            </span>
            <button
              className="ghost-button"
              disabled={isLoading || pagination.current_page >= pagination.last_page}
              onClick={() => fetchSales(pagination.current_page + 1)}
              type="button"
            >
              Next
            </button>
          </div>
        )}
      </section>
    </div>
  );
}

export default Sales;
