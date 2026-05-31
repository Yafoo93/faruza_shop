import React, { useEffect, useMemo, useState } from "react";
import api from "../services/api";
import { checkoutSale } from "../services/sales";

function money(value) {
  return Number(value || 0).toFixed(2);
}

function availableStock(product) {
  return Math.max(Number(product.stock_qty || 0), 0);
}

function POS({ user, onSaleCompleted }) {
  const [products, setProducts] = useState([]);
  const [cart, setCart] = useState([]);
  const [search, setSearch] = useState("");
  const [paymentMethod, setPaymentMethod] = useState("cash");
  const [amountPaid, setAmountPaid] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [isCheckingOut, setIsCheckingOut] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    fetchProducts();
  }, []);

  async function fetchProducts() {
    setIsLoading(true);
    setError("");

    try {
      const res = await api.get("/products");
      setProducts(res.data);
    } catch (err) {
      setError("Could not load products. Check that the Laravel API is running.");
    } finally {
      setIsLoading(false);
    }
  }

  const filteredProducts = useMemo(() => {
    const query = search.trim().toLowerCase();

    return products.filter((product) => {
      if (!query) return availableStock(product) > 0;

      return (
        availableStock(product) > 0 &&
        (product.name.toLowerCase().includes(query) ||
          product.sku.toLowerCase().includes(query) ||
          product.category.toLowerCase().includes(query))
      );
    });
  }, [products, search]);

  const subtotal = useMemo(() => {
    return cart.reduce((total, item) => total + item.quantity * Number(item.selling_price || 0), 0);
  }, [cart]);

  const paid = Number(amountPaid || 0);
  const balance = paid - subtotal;
  const cartCount = cart.reduce((total, item) => total + item.quantity, 0);

  function productInCart(productId) {
    return cart.find((item) => item.id === productId);
  }

  function addToCart(product) {
    setMessage("");
    setError("");

    setCart((current) => {
      const existing = current.find((item) => item.id === product.id);
      const maxQty = availableStock(product);

      if (existing) {
        return current.map((item) =>
          item.id === product.id
            ? { ...item, quantity: Math.min(item.quantity + 1, maxQty) }
            : item
        );
      }

      return [...current, { ...product, quantity: 1 }];
    });
  }

  function updateQuantity(productId, quantity) {
    const product = products.find((item) => item.id === productId);
    const maxQty = availableStock(product || {});
    const nextQuantity = Math.max(1, Math.min(Number(quantity || 1), maxQty));

    setCart((current) =>
      current.map((item) => (item.id === productId ? { ...item, quantity: nextQuantity } : item))
    );
  }

  function removeFromCart(productId) {
    setCart((current) => current.filter((item) => item.id !== productId));
  }

  function clearCart() {
    setCart([]);
    setAmountPaid("");
    setMessage("");
    setError("");
  }

  async function handleCheckout(event) {
    event.preventDefault();
    setError("");
    setMessage("");

    if (cart.length === 0) {
      setError("Add at least one product to the cart.");
      return;
    }

    if (paymentMethod === "cash" && paid < subtotal) {
      setError("Amount paid is less than the cart total.");
      return;
    }

    setIsCheckingOut(true);

    try {
      const payload = {
        cashier_id: user.id,
        payment_method: paymentMethod,
        amount_paid: paymentMethod === "cash" ? paid : subtotal,
        items: cart.map((item) => ({
          product_id: item.id,
          quantity: item.quantity,
        })),
      };

      const res = await checkoutSale(payload);
      setMessage(`Sale #${res.data.sale.id} completed. Change: GHS ${money(res.data.sale.change_due)}`);
      setCart([]);
      setAmountPaid("");
      await fetchProducts();
      onSaleCompleted?.();
    } catch (err) {
      const apiMessage = err.response?.data?.message;
      setError(apiMessage || "Could not complete sale. Check stock and try again.");
    } finally {
      setIsCheckingOut(false);
    }
  }

  return (
    <div className="page-stack">
      <section className="page-header">
        <div>
          <p className="eyebrow">Point of sale</p>
          <h2>Cart</h2>
          <p className="muted">Search products, build the cart, and complete checkout.</p>
        </div>
      </section>

      <section className="pos-layout">
        <div className="panel">
          <div className="table-toolbar">
            <div>
              <h3>Products</h3>
              <p className="muted">{filteredProducts.length} available item(s)</p>
            </div>
            <div className="toolbar-controls single">
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search name, SKU, category"
              />
            </div>
          </div>

          {isLoading ? (
            <p className="empty-state">Loading products...</p>
          ) : filteredProducts.length === 0 ? (
            <p className="empty-state">No in-stock products found.</p>
          ) : (
            <div className="product-picker">
              {filteredProducts.map((product) => {
                const selected = productInCart(product.id);
                const isMaxed = selected?.quantity >= availableStock(product);

                return (
                  <button
                    className="product-tile"
                    disabled={isMaxed}
                    key={product.id}
                    onClick={() => addToCart(product)}
                    type="button"
                  >
                    <span>
                      <strong>{product.name}</strong>
                      <small>{product.sku} · {product.category}</small>
                    </span>
                    <span>
                      <strong>GHS {money(product.selling_price)}</strong>
                      <small>{availableStock(product)} in stock</small>
                    </span>
                  </button>
                );
              })}
            </div>
          )}
        </div>

        <form className="panel cart-panel" onSubmit={handleCheckout}>
          <div className="panel-heading">
            <div>
              <h3>Current cart</h3>
              <p className="muted">{cartCount} unit(s)</p>
            </div>
            <button className="ghost-button" disabled={cart.length === 0} onClick={clearCart} type="button">
              Clear
            </button>
          </div>

          {message && <p className="form-success">{message}</p>}
          {error && <p className="form-error">{error}</p>}

          {cart.length === 0 ? (
            <p className="empty-state">Cart is empty.</p>
          ) : (
            <div className="cart-list">
              {cart.map((item) => (
                <article className="cart-item" key={item.id}>
                  <div>
                    <strong>{item.name}</strong>
                    <span>GHS {money(item.selling_price)} each</span>
                  </div>
                  <div className="cart-item-controls">
                    <input
                      aria-label={`Quantity for ${item.name}`}
                      min="1"
                      max={availableStock(item)}
                      type="number"
                      value={item.quantity}
                      onChange={(e) => updateQuantity(item.id, e.target.value)}
                    />
                    <strong>GHS {money(item.quantity * Number(item.selling_price || 0))}</strong>
                    <button className="danger-button" onClick={() => removeFromCart(item.id)} type="button">
                      Remove
                    </button>
                  </div>
                </article>
              ))}
            </div>
          )}

          <div className="cart-summary">
            <div>
              <span>Subtotal</span>
              <strong>GHS {money(subtotal)}</strong>
            </div>
            <label>
              Payment method
              <select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile money</option>
                <option value="card">Card</option>
              </select>
            </label>
            <label>
              Amount paid
              <input
                min="0"
                step="0.01"
                type="number"
                value={amountPaid}
                onChange={(e) => setAmountPaid(e.target.value)}
                placeholder={money(subtotal)}
              />
            </label>
            <div>
              <span>{balance >= 0 ? "Change due" : "Balance"}</span>
              <strong className={balance < 0 ? "negative-balance" : ""}>GHS {money(Math.abs(balance))}</strong>
            </div>
          </div>

          <button className="primary-button checkout-button" disabled={isCheckingOut || cart.length === 0} type="submit">
            {isCheckingOut ? "Completing sale..." : "Complete sale"}
          </button>
        </form>
      </section>
    </div>
  );
}

export default POS;
