import React, { useEffect, useMemo, useState } from "react";
import { getActivityLogs } from "../services/activityLogs";

function actionLabel(action) {
  return String(action || "").replaceAll("_", " ");
}

function Audit() {
  const [logs, setLogs] = useState([]);
  const [actions, setActions] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [filters, setFilters] = useState({
    search: "",
    action: "",
    from: "",
    to: "",
  });
  const [expandedLogId, setExpandedLogId] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    fetchLogs();
  }, []);

  async function fetchLogs(page = 1, nextFilters = filters) {
    setIsLoading(true);
    setError("");

    const params = Object.fromEntries(
      Object.entries({ ...nextFilters, page }).filter(([, value]) => value !== "")
    );

    try {
      const res = await getActivityLogs(params);
      const paginator = res.data.logs;
      setLogs(paginator.data || []);
      setActions(res.data.actions || []);
      setPagination({
        current_page: paginator.current_page,
        last_page: paginator.last_page,
        from: paginator.from,
        to: paginator.to,
        total: paginator.total,
      });
    } catch (err) {
      const message = err.response?.status === 403
        ? "Only admin users can access audit logs."
        : "Could not load audit logs.";
      setError(message);
    } finally {
      setIsLoading(false);
    }
  }

  function updateFilter(field, value) {
    setFilters((current) => ({ ...current, [field]: value }));
  }

  function applyFilters(event) {
    event.preventDefault();
    fetchLogs(1);
  }

  function resetFilters() {
    const nextFilters = {
      search: "",
      action: "",
      from: "",
      to: "",
    };
    setFilters(nextFilters);
    setExpandedLogId(null);
    fetchLogs(1, nextFilters);
  }

  const pageLabel = useMemo(() => {
    if (!pagination || pagination.total === 0) return "No activity";
    return `${pagination.from || 0}-${pagination.to || 0} of ${pagination.total}`;
  }, [pagination]);

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Audit</p>
          <h2>Activity log</h2>
          <p className="muted">Track product edits, price changes, restocks, sales, refunds, voids, and customer payments.</p>
        </div>
        <button className="ghost-button" disabled={isLoading} onClick={() => fetchLogs()} type="button">
          Refresh
        </button>
      </section>

      <section className="panel">
        <form className="audit-filters" onSubmit={applyFilters}>
          <label>
            Search
            <input
              value={filters.search}
              onChange={(event) => updateFilter("search", event.target.value)}
              placeholder="Description, actor, subject"
            />
          </label>
          <label>
            Action
            <select value={filters.action} onChange={(event) => updateFilter("action", event.target.value)}>
              <option value="">All actions</option>
              {actions.map((action) => (
                <option key={action} value={action}>
                  {actionLabel(action)}
                </option>
              ))}
            </select>
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
            <h3>Events</h3>
            <p className="muted">{pageLabel}</p>
          </div>
        </div>

        {error && <p className="form-error">{error}</p>}

        {isLoading ? (
          <p className="empty-state">Loading activity...</p>
        ) : logs.length === 0 ? (
          <p className="empty-state">No audit logs found.</p>
        ) : (
          <div className="table-wrap">
            <table className="data-table audit-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Actor</th>
                  <th>Action</th>
                  <th>Description</th>
                  <th>Subject</th>
                  <th>Details</th>
                </tr>
              </thead>
              <tbody>
                {logs.map((log) => (
                  <React.Fragment key={log.id}>
                    <tr>
                      <td>{new Date(log.created_at).toLocaleString()}</td>
                      <td>
                        <strong>{log.user?.name || "System"}</strong>
                        <span className="subtext">{log.user?.role || "unknown"}</span>
                      </td>
                      <td>
                        <span className="status-pill in-stock">{actionLabel(log.action)}</span>
                      </td>
                      <td>{log.description}</td>
                      <td>
                        {log.subject_type ? log.subject_type.split("\\").pop() : "None"}
                        {log.subject_id && <span className="subtext">#{log.subject_id}</span>}
                      </td>
                      <td>
                        <button
                          className="ghost-button"
                          onClick={() => setExpandedLogId((current) => (current === log.id ? null : log.id))}
                          type="button"
                        >
                          {expandedLogId === log.id ? "Hide" : "View"}
                        </button>
                      </td>
                    </tr>
                    {expandedLogId === log.id && (
                      <tr className="sale-detail-row">
                        <td colSpan="6">
                          <div className="audit-detail-grid">
                            <pre>{JSON.stringify({ before: log.before, after: log.after, metadata: log.metadata }, null, 2)}</pre>
                            <div>
                              <strong>Request</strong>
                              <span className="subtext">IP: {log.ip_address || "Unknown"}</span>
                              <span className="subtext">Agent: {log.user_agent || "Unknown"}</span>
                            </div>
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
              onClick={() => fetchLogs(pagination.current_page - 1)}
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
              onClick={() => fetchLogs(pagination.current_page + 1)}
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

export default Audit;
