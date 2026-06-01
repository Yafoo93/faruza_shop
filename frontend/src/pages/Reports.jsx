import React, { useState } from "react";
import { downloadSalesReport } from "../services/reports";

function todayValue() {
  return new Date().toISOString().slice(0, 10);
}

function monthStartValue() {
  const date = new Date();
  date.setDate(1);
  return date.toISOString().slice(0, 10);
}

function Reports() {
  const [form, setForm] = useState({
    from: monthStartValue(),
    to: todayValue(),
    format: "pdf",
  });
  const [isExporting, setIsExporting] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  function updateForm(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function handleExport(event) {
    event.preventDefault();
    setIsExporting(true);
    setMessage("");
    setError("");

    try {
      await downloadSalesReport(form);
      setMessage("Report downloaded.");
    } catch (err) {
      const status = err.response?.status;
      setError(status === 403 ? "Only admin users can export reports." : "Could not export report.");
    } finally {
      setIsExporting(false);
    }
  }

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Reports</p>
          <h2>Export sales report</h2>
          <p className="muted">Generate period reports with sales totals, profit, products, and cashier breakdowns.</p>
        </div>
      </section>

      <section className="panel">
        <form className="report-form" onSubmit={handleExport}>
          <label>
            From
            <input
              type="date"
              value={form.from}
              onChange={(event) => updateForm("from", event.target.value)}
            />
          </label>
          <label>
            To
            <input
              type="date"
              value={form.to}
              onChange={(event) => updateForm("to", event.target.value)}
            />
          </label>
          <label>
            Format
            <select value={form.format} onChange={(event) => updateForm("format", event.target.value)}>
              <option value="pdf">PDF</option>
              <option value="excel">Excel</option>
              <option value="csv">CSV</option>
            </select>
          </label>
          <button className="primary-button form-submit" disabled={isExporting} type="submit">
            {isExporting ? "Exporting..." : "Download report"}
          </button>
        </form>

        {message && <p className="form-success">{message}</p>}
        {error && <p className="form-error">{error}</p>}
      </section>

      <section className="panel">
        <div className="panel-heading">
          <div>
            <h3>Included sections</h3>
            <p className="muted">These exports are admin-only because they include profit.</p>
          </div>
        </div>
        <div className="report-feature-grid">
          <article>
            <strong>Sales summary</strong>
            <span>Transactions, items sold, refunds, net sales, and estimated profit.</span>
          </article>
          <article>
            <strong>Product breakdown</strong>
            <span>Net quantity sold, net sales, and profit per product.</span>
          </article>
          <article>
            <strong>Cashier breakdown</strong>
            <span>Transactions, items sold, net sales, and profit by cashier.</span>
          </article>
          <article>
            <strong>Receipt exports</strong>
            <span>Each sale can be downloaded from the Sales page as PDF, Excel, or CSV.</span>
          </article>
        </div>
      </section>
    </div>
  );
}

export default Reports;
