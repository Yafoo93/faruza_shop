import React, { useEffect, useMemo, useState } from "react";
import { createUser, disableUser, enableUser, getUsers, updateUser } from "../services/users";

const emptyForm = {
  name: "",
  email: "",
  password: "",
  role: "cashier",
};

function Users({ currentUser }) {
  const [users, setUsers] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [editingId, setEditingId] = useState(null);
  const [filters, setFilters] = useState({
    search: "",
    role: "",
    status: "active",
  });
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    fetchUsers();
  }, [filters.status, filters.role]);

  async function fetchUsers(nextFilters = filters) {
    setIsLoading(true);
    setError("");

    try {
      const params = Object.fromEntries(
        Object.entries(nextFilters).filter(([, value]) => value !== "")
      );
      const res = await getUsers(params);
      setUsers(res.data);
    } catch (err) {
      setError("Could not load users.");
    } finally {
      setIsLoading(false);
    }
  }

  const filteredUsers = useMemo(() => {
    const query = filters.search.trim().toLowerCase();

    return users.filter((user) => {
      return (
        !query ||
        user.name.toLowerCase().includes(query) ||
        user.email.toLowerCase().includes(query)
      );
    });
  }, [users, filters.search]);

  const totals = useMemo(() => {
    return users.reduce(
      (summary, user) => {
        summary.total += 1;
        summary[user.role] += 1;
        if (user.disabled_at) summary.disabled += 1;
        return summary;
      },
      { total: 0, admin: 0, cashier: 0, disabled: 0 }
    );
  }, [users]);

  function updateFormField(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  function updateFilter(field, value) {
    setFilters((current) => ({ ...current, [field]: value }));
  }

  function resetForm() {
    setForm(emptyForm);
    setEditingId(null);
    setError("");
    setMessage("");
  }

  function startEdit(user) {
    setEditingId(user.id);
    setForm({
      name: user.name || "",
      email: user.email || "",
      password: "",
      role: user.role || "cashier",
    });
    setMessage("");
    setError("");
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setIsSaving(true);
    setError("");
    setMessage("");

    const payload = {
      name: form.name,
      email: form.email,
      role: form.role,
    };

    if (form.password) {
      payload.password = form.password;
    }

    try {
      if (editingId) {
        await updateUser(editingId, payload);
        setMessage("User updated.");
      } else {
        await createUser({ ...payload, password: form.password });
        setMessage("User created.");
      }

      resetForm();
      await fetchUsers();
    } catch (err) {
      const apiMessage = err.response?.data?.message;
      setError(apiMessage || "Could not save user. Check the required fields.");
    } finally {
      setIsSaving(false);
    }
  }

  async function handleDisable(user) {
    const confirmed = window.confirm(`Disable ${user.name}?`);
    if (!confirmed) return;

    setError("");
    setMessage("");

    try {
      await disableUser(user.id);
      setMessage("User disabled.");
      await fetchUsers();
    } catch (err) {
      setError(err.response?.data?.message || "Could not disable user.");
    }
  }

  async function handleEnable(user) {
    setError("");
    setMessage("");

    try {
      await enableUser(user.id);
      setMessage("User enabled.");
      await fetchUsers();
    } catch (err) {
      setError("Could not enable user.");
    }
  }

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Access control</p>
          <h2>Users</h2>
          <p className="muted">Create, edit, disable, and restore admin or attendant accounts.</p>
        </div>
      </section>

      <section className="stat-grid">
        <article className="stat-card">
          <span>Shown users</span>
          <strong>{totals.total}</strong>
        </article>
        <article className="stat-card">
          <span>Admins</span>
          <strong>{totals.admin}</strong>
        </article>
        <article className="stat-card">
          <span>Attendants</span>
          <strong>{totals.cashier}</strong>
        </article>
        <article className="stat-card warning">
          <span>Disabled</span>
          <strong>{totals.disabled}</strong>
        </article>
      </section>

      <section className="panel">
        <div className="panel-heading">
          <div>
            <h3>{editingId ? "Edit user" : "Create user"}</h3>
            <p className="muted">Use cashier for attendants and admin for owners/managers.</p>
          </div>
          {editingId && (
            <button className="ghost-button" onClick={resetForm} type="button">
              Cancel edit
            </button>
          )}
        </div>

        <form className="user-form" onSubmit={handleSubmit}>
          <label>
            Name
            <input
              value={form.name}
              onChange={(event) => updateFormField("name", event.target.value)}
              required
            />
          </label>
          <label>
            Email
            <input
              type="email"
              value={form.email}
              onChange={(event) => updateFormField("email", event.target.value)}
              required
            />
          </label>
          <label>
            Password
            <input
              minLength="6"
              type="password"
              value={form.password}
              onChange={(event) => updateFormField("password", event.target.value)}
              required={!editingId}
              placeholder={editingId ? "Leave blank to keep password" : ""}
            />
          </label>
          <label>
            Role
            <select value={form.role} onChange={(event) => updateFormField("role", event.target.value)}>
              <option value="cashier">Attendant / cashier</option>
              <option value="admin">Admin</option>
            </select>
          </label>
          <button className="primary-button form-submit" disabled={isSaving} type="submit">
            {isSaving ? "Saving..." : editingId ? "Save user" : "Create user"}
          </button>
        </form>

        {message && <p className="form-success">{message}</p>}
        {error && <p className="form-error">{error}</p>}
      </section>

      <section className="panel">
        <div className="table-toolbar">
          <div>
            <h3>Account list</h3>
            <p className="muted">{filteredUsers.length} user(s) shown</p>
          </div>
          <div className="toolbar-controls products-toolbar">
            <input
              value={filters.search}
              onChange={(event) => updateFilter("search", event.target.value)}
              placeholder="Search name or email"
            />
            <select value={filters.role} onChange={(event) => updateFilter("role", event.target.value)}>
              <option value="">All roles</option>
              <option value="admin">Admins</option>
              <option value="cashier">Attendants</option>
            </select>
            <select value={filters.status} onChange={(event) => updateFilter("status", event.target.value)}>
              <option value="active">Active</option>
              <option value="disabled">Disabled</option>
              <option value="all">All accounts</option>
            </select>
          </div>
        </div>

        {isLoading ? (
          <p className="empty-state">Loading users...</p>
        ) : filteredUsers.length === 0 ? (
          <p className="empty-state">No users found.</p>
        ) : (
          <div className="table-wrap">
            <table className="data-table users-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredUsers.map((user) => (
                  <tr key={user.id}>
                    <td>
                      <strong>{user.name}</strong>
                      {user.id === currentUser.id && <span className="subtext">Current account</span>}
                    </td>
                    <td>{user.email}</td>
                    <td>{user.role === "cashier" ? "attendant" : user.role}</td>
                    <td>
                      <span className={`status-pill ${user.disabled_at ? "out-of-stock" : "in-stock"}`}>
                        {user.disabled_at ? "Disabled" : "Active"}
                      </span>
                    </td>
                    <td>{new Date(user.created_at).toLocaleDateString()}</td>
                    <td>
                      <div className="row-actions wrap">
                        <button className="ghost-button" onClick={() => startEdit(user)} type="button">
                          Edit
                        </button>
                        {user.disabled_at ? (
                          <button className="ghost-button" onClick={() => handleEnable(user)} type="button">
                            Enable
                          </button>
                        ) : (
                          <button
                            className="danger-button"
                            disabled={user.id === currentUser.id}
                            onClick={() => handleDisable(user)}
                            type="button"
                          >
                            Disable
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

export default Users;
