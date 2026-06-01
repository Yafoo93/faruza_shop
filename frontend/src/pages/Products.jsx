import React, { useEffect, useMemo, useState } from "react";
import api from "../services/api";

const emptyProduct = {
  name: "",
  sku: "",
  category: "",
  cost_price: "",
  selling_price: "",
  stock_qty: "",
  min_stock_threshold: 10,
  expiry_date: "",
  image: "",
};

function money(value) {
  return Number(value || 0).toFixed(2);
}

function dateInputValue(value) {
  if (!value) return "";
  return String(value).slice(0, 10);
}

function stockStatus(product) {
  if (Number(product.stock_qty) <= 0) return "Out of Stock";
  if (Number(product.stock_qty) <= 10) return "Critical Stock";
  if (Number(product.stock_qty) <= Math.max(Number(product.min_stock_threshold || 0), 20)) {
    return "Low Stock";
  }
  return "In Stock";
}

function ProductList({ user, onProductsChanged }) {
  const isAdmin = user.role === "admin";
  const [products, setProducts] = useState([]);
  const [form, setForm] = useState(emptyProduct);
  const [editingId, setEditingId] = useState(null);
  const [search, setSearch] = useState("");
  const [category, setCategory] = useState("all");
  const [productStatus, setProductStatus] = useState("active");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    fetchProducts();
  }, []);

  async function fetchProducts() {
    setIsLoading(true);
    setError("");

    try {
      const res = await api.get("/products", {
        params: isAdmin ? { status: productStatus } : undefined,
      });
      setProducts(res.data);
    } catch (err) {
      setError("Could not load products. Check that the Laravel API is running.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    fetchProducts();
  }, [productStatus]);

  const categories = useMemo(() => {
    const unique = products
      .map((product) => product.category)
      .filter(Boolean)
      .sort((a, b) => a.localeCompare(b));

    return ["all", ...new Set(unique)];
  }, [products]);

  const filteredProducts = useMemo(() => {
    const query = search.trim().toLowerCase();

    return products.filter((product) => {
      const matchesSearch =
        !query ||
        product.name.toLowerCase().includes(query) ||
        product.sku.toLowerCase().includes(query) ||
        product.category.toLowerCase().includes(query);

      const matchesCategory = category === "all" || product.category === category;
      return matchesSearch && matchesCategory;
    });
  }, [products, search, category]);

  const totals = useMemo(() => {
    return products.reduce(
      (summary, product) => {
        const stock = Number(product.stock_qty || 0);
        summary.items += 1;
        summary.units += stock;
        summary.value += stock * Number(product.cost_price || 0);
        summary.lowStock += stock <= Number(product.min_stock_threshold || 0) ? 1 : 0;
        return summary;
      },
      { items: 0, units: 0, value: 0, lowStock: 0 }
    );
  }, [products]);

  function updateForm(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  function resetForm() {
    setForm(emptyProduct);
    setEditingId(null);
    setError("");
    setMessage("");
  }

  function startEdit(product) {
    setEditingId(product.id);
    setForm({
      name: product.name || "",
      sku: product.sku || "",
      category: product.category || "",
      cost_price: product.cost_price || "",
      selling_price: product.selling_price || "",
      stock_qty: product.stock_qty || "",
      min_stock_threshold: product.min_stock_threshold || 10,
      expiry_date: dateInputValue(product.expiry_date),
      image: product.image || "",
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
      ...form,
      cost_price: Number(form.cost_price),
      selling_price: Number(form.selling_price),
      stock_qty: Number(form.stock_qty),
      min_stock_threshold: Number(form.min_stock_threshold || 10),
      expiry_date: form.expiry_date || null,
      image: form.image || null,
    };

    try {
      if (editingId) {
        await api.put(`/products/${editingId}`, payload);
        setMessage("Product updated.");
      } else {
        await api.post("/products", payload);
        setMessage("Product added.");
      }

      resetForm();
      await fetchProducts();
      onProductsChanged?.();
    } catch (err) {
      setError("Could not save product. Check required fields and unique SKU.");
    } finally {
      setIsSaving(false);
    }
  }

  async function archiveProduct(product) {
    const confirmed = window.confirm(`Archive ${product.name}?`);
    if (!confirmed) return;

    setError("");
    setMessage("");

    try {
      await api.delete(`/products/${product.id}`);
      setMessage("Product archived.");
      await fetchProducts();
      onProductsChanged?.();
    } catch (err) {
      setError("Could not archive product.");
    }
  }

  async function restoreProduct(product) {
    setError("");
    setMessage("");

    try {
      await api.post(`/products/${product.id}/restore`);
      setMessage("Product restored.");
      await fetchProducts();
      onProductsChanged?.();
    } catch (err) {
      setError("Could not restore product.");
    }
  }

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Inventory</p>
          <h2>Products</h2>
          <p className="muted">
            {isAdmin
              ? "Add, edit, delete, search, and monitor product stock."
              : "Search products and check selling price availability."}
          </p>
        </div>
      </section>

      <section className="stat-grid">
        <article className="stat-card">
          <span>Total products</span>
          <strong>{totals.items}</strong>
        </article>
        <article className="stat-card">
          <span>Units in stock</span>
          <strong>{totals.units}</strong>
        </article>
        {isAdmin && (
          <article className="stat-card">
            <span>Stock value</span>
            <strong>GHS {money(totals.value)}</strong>
          </article>
        )}
        <article className="stat-card warning">
          <span>Low stock</span>
          <strong>{totals.lowStock}</strong>
        </article>
      </section>

      {isAdmin && (
        <section className="panel">
          <div className="panel-heading">
            <div>
              <h3>{editingId ? "Edit product" : "Add product"}</h3>
              <p className="muted">Use SKU for product code or barcode value.</p>
            </div>
            {editingId && (
              <button className="ghost-button" onClick={resetForm} type="button">
                Cancel edit
              </button>
            )}
          </div>

          <form className="product-form" onSubmit={handleSubmit}>
            <label>
              Product name
              <input
                value={form.name}
                onChange={(e) => updateForm("name", e.target.value)}
                placeholder="Milo 500g"
                required
              />
            </label>
            <label>
              SKU / product code
              <input
                value={form.sku}
                onChange={(e) => updateForm("sku", e.target.value)}
                placeholder="ML-500"
                required
              />
            </label>
            <label>
              Category
              <input
                value={form.category}
                onChange={(e) => updateForm("category", e.target.value)}
                placeholder="Drinks"
                required
              />
            </label>
            <label>
              Cost price
              <input
                min="0"
                step="0.01"
                type="number"
                value={form.cost_price}
                onChange={(e) => updateForm("cost_price", e.target.value)}
                required
              />
            </label>
            <label>
              Selling price
              <input
                min="0"
                step="0.01"
                type="number"
                value={form.selling_price}
                onChange={(e) => updateForm("selling_price", e.target.value)}
                required
              />
            </label>
            <label>
              Stock quantity
              <input
                min="0"
                type="number"
                value={form.stock_qty}
                onChange={(e) => updateForm("stock_qty", e.target.value)}
                required
              />
            </label>
            <label>
              Minimum threshold
              <input
                min="0"
                type="number"
                value={form.min_stock_threshold}
                onChange={(e) => updateForm("min_stock_threshold", e.target.value)}
              />
            </label>
            <label>
              Expiry date
              <input
                type="date"
                value={form.expiry_date}
                onChange={(e) => updateForm("expiry_date", e.target.value)}
              />
            </label>
            <label className="span-2">
              Image URL
              <input
                value={form.image}
                onChange={(e) => updateForm("image", e.target.value)}
                placeholder="Optional product image link"
              />
            </label>
            <button className="primary-button form-submit" disabled={isSaving} type="submit">
              {isSaving ? "Saving..." : editingId ? "Save changes" : "Add product"}
            </button>
          </form>
        </section>
      )}

      <section className="panel">
        <div className="table-toolbar">
          <div>
            <h3>Product list</h3>
            <p className="muted">{filteredProducts.length} product(s) shown</p>
          </div>
            <div className={isAdmin ? "toolbar-controls products-toolbar" : "toolbar-controls"}>
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              placeholder="Search name, SKU, category"
            />
            <select value={category} onChange={(e) => setCategory(e.target.value)}>
              {categories.map((item) => (
                <option key={item} value={item}>
                  {item === "all" ? "All categories" : item}
                </option>
              ))}
            </select>
            {isAdmin && (
              <select value={productStatus} onChange={(e) => setProductStatus(e.target.value)}>
                <option value="active">Active products</option>
                <option value="archived">Archived products</option>
                <option value="all">All products</option>
              </select>
            )}
          </div>
        </div>

        {message && <p className="form-success">{message}</p>}
        {error && <p className="form-error">{error}</p>}

        {isLoading ? (
          <p className="empty-state">Loading products...</p>
        ) : filteredProducts.length === 0 ? (
          <p className="empty-state">No products found.</p>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>SKU</th>
                  <th>Category</th>
                  <th>Stock</th>
                  <th>Status</th>
                  {isAdmin && <th>Cost</th>}
                  <th>Selling</th>
                  {isAdmin && <th>Actions</th>}
                </tr>
              </thead>
              <tbody>
                {filteredProducts.map((product) => (
                  <tr key={product.id}>
                    <td>
                      <strong>{product.name}</strong>
                      {product.archived_at && <span className="subtext warning-text">Archived</span>}
                      {product.expiry_date && (
                        <span className="subtext">Expires {dateInputValue(product.expiry_date)}</span>
                      )}
                    </td>
                    <td>{product.sku}</td>
                    <td>{product.category}</td>
                    <td>{product.stock_qty}</td>
                    <td>
                      <span className={`status-pill ${stockStatus(product).toLowerCase().replaceAll(" ", "-")}`}>
                        {stockStatus(product)}
                      </span>
                    </td>
                    {isAdmin && <td>GHS {money(product.cost_price)}</td>}
                    <td>GHS {money(product.selling_price)}</td>
                    {isAdmin && (
                      <td>
                        <div className="row-actions">
                          <button className="ghost-button" onClick={() => startEdit(product)}>
                            Edit
                          </button>
                          {product.archived_at ? (
                            <button className="ghost-button" onClick={() => restoreProduct(product)}>
                              Restore
                            </button>
                          ) : (
                            <button className="danger-button" onClick={() => archiveProduct(product)}>
                              Archive
                            </button>
                          )}
                        </div>
                      </td>
                    )}
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

export default ProductList;
