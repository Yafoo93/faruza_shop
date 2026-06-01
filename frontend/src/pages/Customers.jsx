import React, { useEffect, useMemo, useState } from "react";
import {
  createCustomer,
  getCustomer,
  getCustomers,
  recordCustomerPayment,
  updateCustomer,
} from "../services/customers";

function money(value) {
  return Number(value || 0).toFixed(2);
}

const emptyForm = {
  name: "",
  phone: "",
  email: "",
  address: "",
  credit_limit: "",
  notes: "",
};

function Customers() {
  const [customers, setCustomers] = useState([]);
  const [summary, setSummary] = useState({ customers_count: 0, owing_count: 0, outstanding_balance: 0 });
  const [filters, setFilters] = useState({ search: "", status: "all" });
  const [form, setForm] = useState(emptyForm);
  const [editingId, setEditingId] = useState(null);
  const [selectedCustomer, setSelectedCustomer] = useState(null);
  const [paymentForm, setPaymentForm] = useState({ amount: "", payment_method: "cash", notes: "" });
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  useEffect(() => {
    fetchCustomers();
  }, []);

  async function fetchCustomers(params = filters) {
    setIsLoading(true);
    setError("");

    try {
      const res = await getCustomers(params);
      setCustomers(res.data.customers?.data || []);
      setSummary(res.data.summary || summary);
    } catch (err) {
      setError("Could not load customers. Check that the Laravel API is running.");
    } finally {
      setIsLoading(false);
    }
  }

  function updateFilter(field, value) {
    setFilters((current) => ({ ...current, [field]: value }));
  }

  function updateForm(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  function editCustomer(customer) {
    setEditingId(customer.id);
    setForm({
      name: customer.name || "",
      phone: customer.phone || "",
      email: customer.email || "",
      address: customer.address || "",
      credit_limit: customer.credit_limit || "",
      notes: customer.notes || "",
    });
    setMessage("");
    setError("");
  }

  function resetForm() {
    setEditingId(null);
    setForm(emptyForm);
  }

  async function submitForm(event) {
    event.preventDefault();
    setIsSaving(true);
    setError("");
    setMessage("");

    const payload = {
      ...form,
      credit_limit: Number(form.credit_limit || 0),
    };

    try {
      if (editingId) {
        await updateCustomer(editingId, payload);
        setMessage("Customer updated.");
      } else {
        await createCustomer(payload);
        setMessage("Customer created.");
      }
      resetForm();
      await fetchCustomers();
    } catch (err) {
      setError(err.response?.data?.message || "Could not save customer.");
    } finally {
      setIsSaving(false);
    }
  }

  async function openLedger(customer) {
    setError("");
    setMessage("");
    try {
      const res = await getCustomer(customer.id);
      setSelectedCustomer(res.data.customer);
      setPaymentForm({ amount: "", payment_method: "cash", notes: "" });
    } catch (err) {
      setError("Could not load customer ledger.");
    }
  }

  async function submitPayment(event) {
    event.preventDefault();
    if (!selectedCustomer) return;

    setIsSaving(true);
    setError("");
    setMessage("");

    try {
      await recordCustomerPayment(selectedCustomer.id, {
        amount: Number(paymentForm.amount || 0),
        payment_method: paymentForm.payment_method,
        notes: paymentForm.notes || null,
      });
      setMessage("Payment recorded.");
      await fetchCustomers();
      await openLedger(selectedCustomer);
    } catch (err) {
      setError(err.response?.data?.message || "Could not record payment.");
    } finally {
      setIsSaving(false);
    }
  }

  const owingCustomers = useMemo(
    () => customers.filter((customer) => Number(customer.outstanding_balance || 0) > 0),
    [customers]
  );

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Customer credit</p>
          <h2>Customers</h2>
          <p className="muted">Manage customer debt, credit limits, and repayments.</p>
        </div>
      </section>

      <section className="stat-grid">
        <article className="stat-card">
          <span>Customers</span>
          <strong>{summary.customers_count}</strong>
        </article>
        <article className="stat-card warning">
          <span>Owing</span>
          <strong>{summary.owing_count}</strong>
        </article>
        <article className="stat-card warning">
          <span>Outstanding</span>
          <strong>GHS {money(summary.outstanding_balance)}</strong>
        </article>
        <article className="stat-card">
          <span>Shown owing</span>
          <strong>{owingCustomers.length}</strong>
        </article>
      </section>

      <section className="panel">
        <form className="customer-form" onSubmit={submitForm}>
          <label>
            Name
            <input value={form.name} onChange={(event) => updateForm("name", event.target.value)} required />
          </label>
          <label>
            Phone
            <input value={form.phone} onChange={(event) => updateForm("phone", event.target.value)} />
          </label>
          <label>
            Email
            <input type="email" value={form.email} onChange={(event) => updateForm("email", event.target.value)} />
          </label>
          <label>
            Credit limit
            <input min="0" step="0.01" type="number" value={form.credit_limit} onChange={(event) => updateForm("credit_limit", event.target.value)} />
          </label>
          <label className="span-2">
            Address
            <input value={form.address} onChange={(event) => updateForm("address", event.target.value)} />
          </label>
          <label className="span-2">
            Notes
            <input value={form.notes} onChange={(event) => updateForm("notes", event.target.value)} />
          </label>
          <div className="filter-actions form-submit">
            <button className="primary-button" disabled={isSaving} type="submit">
              {isSaving ? "Saving..." : editingId ? "Save customer" : "Add customer"}
            </button>
            {editingId && (
              <button className="ghost-button" onClick={resetForm} type="button">
                Cancel
              </button>
            )}
          </div>
        </form>
      </section>

      <section className="panel">
        <div className="panel-heading">
          <div>
            <h3>Customer balances</h3>
            <p className="muted">Search by name, phone, or email.</p>
          </div>
          <form className="toolbar-controls products-toolbar" onSubmit={(event) => { event.preventDefault(); fetchCustomers(); }}>
            <input value={filters.search} onChange={(event) => updateFilter("search", event.target.value)} placeholder="Search customers" />
            <select value={filters.status} onChange={(event) => updateFilter("status", event.target.value)}>
              <option value="all">All</option>
              <option value="owing">Owing</option>
              <option value="clear">Clear</option>
            </select>
            <button className="ghost-button" disabled={isLoading} type="submit">Apply</button>
          </form>
        </div>

        {error && <p className="form-error">{error}</p>}
        {message && <p className="form-success">{message}</p>}

        {isLoading ? (
          <p className="empty-state">Loading customers...</p>
        ) : customers.length === 0 ? (
          <p className="empty-state">No customers found.</p>
        ) : (
          <div className="table-wrap">
            <table className="data-table customers-table">
              <thead>
                <tr>
                  <th>Customer</th>
                  <th>Contact</th>
                  <th>Credit limit</th>
                  <th>Outstanding</th>
                  <th>Sales</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {customers.map((customer) => (
                  <tr key={customer.id}>
                    <td>
                      <strong>{customer.name}</strong>
                      {customer.notes && <span className="subtext">{customer.notes}</span>}
                    </td>
                    <td>
                      {customer.phone || "No phone"}
                      {customer.email && <span className="subtext">{customer.email}</span>}
                    </td>
                    <td>GHS {money(customer.credit_limit)}</td>
                    <td>
                      <strong className={Number(customer.outstanding_balance || 0) > 0 ? "warning-text" : ""}>
                        GHS {money(customer.outstanding_balance)}
                      </strong>
                    </td>
                    <td>{customer.sales_count || 0}</td>
                    <td>
                      <div className="row-actions wrap">
                        <button className="ghost-button" onClick={() => openLedger(customer)} type="button">Ledger</button>
                        <button className="ghost-button" onClick={() => editCustomer(customer)} type="button">Edit</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {selectedCustomer && (
        <section className="panel">
          <div className="panel-heading">
            <div>
              <h3>{selectedCustomer.name}</h3>
              <p className="muted">Outstanding balance: GHS {money(selectedCustomer.outstanding_balance)}</p>
            </div>
            <button className="ghost-button" onClick={() => setSelectedCustomer(null)} type="button">Close</button>
          </div>

          <form className="report-form" onSubmit={submitPayment}>
            <label>
              Payment amount
              <input min="0.01" max={selectedCustomer.outstanding_balance} step="0.01" type="number" value={paymentForm.amount} onChange={(event) => setPaymentForm((current) => ({ ...current, amount: event.target.value }))} />
            </label>
            <label>
              Method
              <select value={paymentForm.payment_method} onChange={(event) => setPaymentForm((current) => ({ ...current, payment_method: event.target.value }))}>
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile money</option>
                <option value="card">Card</option>
              </select>
            </label>
            <label>
              Notes
              <input value={paymentForm.notes} onChange={(event) => setPaymentForm((current) => ({ ...current, notes: event.target.value }))} />
            </label>
            <button className="primary-button" disabled={isSaving || Number(selectedCustomer.outstanding_balance || 0) <= 0} type="submit">Record payment</button>
          </form>

          <div className="customer-ledger-grid">
            <div>
              <h4>Recent credit sales</h4>
              <div className="compact-list">
                {(selectedCustomer.sales || []).length === 0 ? (
                  <p className="empty-state">No sales for this customer.</p>
                ) : selectedCustomer.sales.map((sale) => (
                  <div className="compact-list-row" key={sale.id}>
                    <span>
                      <strong>Sale #{sale.id}</strong>
                      <small>{sale.payment_status} - {new Date(sale.created_at).toLocaleString()}</small>
                    </span>
                    <strong>GHS {money(sale.credit_amount)}</strong>
                  </div>
                ))}
              </div>
            </div>
            <div>
              <h4>Recent payments</h4>
              <div className="compact-list">
                {(selectedCustomer.payments || []).length === 0 ? (
                  <p className="empty-state">No payments recorded.</p>
                ) : selectedCustomer.payments.map((payment) => (
                  <div className="compact-list-row" key={payment.id}>
                    <span>
                      <strong>{payment.payment_method}</strong>
                      <small>{new Date(payment.created_at).toLocaleString()}</small>
                    </span>
                    <strong>GHS {money(payment.amount)}</strong>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </section>
      )}
    </div>
  );
}

export default Customers;
