import React, { useEffect, useMemo, useState } from "react";
import api from "../services/api";

function RestockProduct({ user, onRestocked }) {
  const [products, setProducts] = useState([]);
  const [selectedId, setSelectedId] = useState("");
  const [quantity, setQuantity] = useState("");
  const [costPrice, setCostPrice] = useState("");
  const [sellingPrice, setSellingPrice] = useState("");
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  useEffect(() => {
    fetchProducts();
  }, []);

  const selectedProduct = useMemo(() => {
    return products.find((product) => String(product.id) === String(selectedId));
  }, [products, selectedId]);

  useEffect(() => {
    if (!selectedProduct) return;

    setCostPrice(selectedProduct.cost_price || "");
    setSellingPrice(selectedProduct.selling_price || "");
  }, [selectedProduct]);

  async function fetchProducts() {
    setIsLoading(true);
    setError("");

    try {
      const res = await api.get("/products");
      setProducts(res.data);
    } catch (err) {
      setError("Could not load products.");
    } finally {
      setIsLoading(false);
    }
  }

  async function handleRestock(event) {
    event.preventDefault();
    setIsSaving(true);
    setMessage("");
    setError("");

    try {
      const res = await api.post(`/products/${selectedId}/restock`, {
        quantity_added: Number(quantity),
        cost_price: Number(costPrice),
        selling_price: Number(sellingPrice),
        restocked_by: user.id,
      });

      setMessage(`Restocked ${res.data.product.name}. New stock: ${res.data.product.stock_qty}.`);
      setQuantity("");
      await fetchProducts();
      onRestocked?.();
    } catch (err) {
      setError("Could not restock product. The stock history table may still need its migration.");
    } finally {
      setIsSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Inventory</p>
          <h2>Restock product</h2>
          <p className="muted">Update quantity, cost price, and selling price in one flow.</p>
        </div>
      </section>

      <section className="panel restock-layout">
        <form className="form-stack" onSubmit={handleRestock}>
          <label>
            Product
            <select
              value={selectedId}
              onChange={(event) => setSelectedId(event.target.value)}
              required
              disabled={isLoading}
            >
              <option value="">Select product</option>
              {products.map((product) => (
                <option key={product.id} value={product.id}>
                  {product.name} - Stock: {product.stock_qty}
                </option>
              ))}
            </select>
          </label>

          <label>
            Quantity added
            <input
              min="1"
              type="number"
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
              required
            />
          </label>

          <label>
            Cost price
            <input
              min="0"
              step="0.01"
              type="number"
              value={costPrice}
              onChange={(event) => setCostPrice(event.target.value)}
              required
            />
          </label>

          <label>
            Selling price
            <input
              min="0"
              step="0.01"
              type="number"
              value={sellingPrice}
              onChange={(event) => setSellingPrice(event.target.value)}
              required
            />
          </label>

          <button className="primary-button" disabled={isSaving || isLoading} type="submit">
            {isSaving ? "Restocking..." : "Save restock"}
          </button>
        </form>

        <aside className="restock-summary">
          <h3>Selected product</h3>
          {selectedProduct ? (
            <dl>
              <div>
                <dt>Name</dt>
                <dd>{selectedProduct.name}</dd>
              </div>
              <div>
                <dt>Current stock</dt>
                <dd>{selectedProduct.stock_qty}</dd>
              </div>
              <div>
                <dt>Category</dt>
                <dd>{selectedProduct.category}</dd>
              </div>
              <div>
                <dt>Threshold</dt>
                <dd>{selectedProduct.min_stock_threshold}</dd>
              </div>
            </dl>
          ) : (
            <p className="empty-state">Choose a product to see current stock details.</p>
          )}
        </aside>
      </section>

      {message && <p className="form-success">{message}</p>}
      {error && <p className="form-error">{error}</p>}
    </div>
  );
}

export default RestockProduct;
