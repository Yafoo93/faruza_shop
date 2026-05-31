import React, { useState } from "react";
import Login from "./pages/Login";
import POS from "./pages/POS";
import ProductList from "./pages/Products";
import RestockProduct from "./pages/RestockProduct";

function App() {
  const [user, setUser] = useState(null);
  const [activeView, setActiveView] = useState("products");
  const [productsVersion, setProductsVersion] = useState(0);

  if (!user) return <Login onLogin={setUser} />;

  const isAdmin = user.role === "admin";
  const refreshProducts = () => setProductsVersion((version) => version + 1);
  const logout = () => {
    localStorage.removeItem("token");
    setUser(null);
  };

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div>
          <p className="eyebrow">F mart</p>
          <h1>Stock Desk</h1>
        </div>

        <nav className="side-nav" aria-label="Main navigation">
          <button
            className={activeView === "pos" ? "active" : ""}
            onClick={() => setActiveView("pos")}
          >
            Cart
          </button>
          <button
            className={activeView === "products" ? "active" : ""}
            onClick={() => setActiveView("products")}
          >
            Products
          </button>
          {isAdmin && (
            <button
              className={activeView === "restock" ? "active" : ""}
              onClick={() => setActiveView("restock")}
            >
              Restock
            </button>
          )}
        </nav>

        <div className="user-card">
          <div>
            <strong>{user.name}</strong>
            <span>{user.role}</span>
          </div>
          <button className="ghost-button" onClick={logout}>
            Logout
          </button>
        </div>
      </aside>

      <main className="main-content">
        {activeView === "pos" && (
          <POS key={productsVersion} user={user} onSaleCompleted={refreshProducts} />
        )}

        {activeView === "products" && (
          <ProductList
            key={productsVersion}
            user={user}
            onProductsChanged={refreshProducts}
          />
        )}

        {activeView === "restock" && isAdmin && (
          <RestockProduct user={user} onRestocked={refreshProducts} />
        )}
      </main>
    </div>
  );
}

export default App;
