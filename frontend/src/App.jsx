import React, { useState } from "react";
import LowStockAlert from "./components/LowStockAlert";
import OfflineNotice from "./components/OfflineNotice";
import Audit from "./pages/Audit";
import Customers from "./pages/Customers";
import Dashboard from "./pages/Dashboard";
import Inventory from "./pages/Inventory";
import Login from "./pages/Login";
import POS from "./pages/POS";
import ProductList from "./pages/Products";
import Reports from "./pages/Reports";
import RestockProduct from "./pages/RestockProduct";
import Sales from "./pages/Sales";
import Users from "./pages/Users";

function App() {
  const [user, setUser] = useState(null);
  const [activeView, setActiveView] = useState("pos");
  const [productsVersion, setProductsVersion] = useState(0);

  function handleLogin(nextUser) {
    setUser(nextUser);
    setActiveView(nextUser.role === "admin" ? "dashboard" : "pos");
  }

  if (!user) return <Login onLogin={handleLogin} />;

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
          {isAdmin && (
            <button
              className={activeView === "dashboard" ? "active" : ""}
              onClick={() => setActiveView("dashboard")}
            >
              Dashboard
            </button>
          )}
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
          <button
            className={activeView === "sales" ? "active" : ""}
            onClick={() => setActiveView("sales")}
          >
            Sales
          </button>
          {isAdmin && (
            <button
              className={activeView === "customers" ? "active" : ""}
              onClick={() => setActiveView("customers")}
            >
              Customers
            </button>
          )}
          {isAdmin && (
            <button
              className={activeView === "inventory" ? "active" : ""}
              onClick={() => setActiveView("inventory")}
            >
              Inventory
            </button>
          )}
          {isAdmin && (
            <button
              className={activeView === "restock" ? "active" : ""}
              onClick={() => setActiveView("restock")}
            >
              Restock
            </button>
          )}
          {isAdmin && (
            <button
              className={activeView === "reports" ? "active" : ""}
              onClick={() => setActiveView("reports")}
            >
              Reports
            </button>
          )}
          {isAdmin && (
            <button
              className={activeView === "audit" ? "active" : ""}
              onClick={() => setActiveView("audit")}
            >
              Audit
            </button>
          )}
          {isAdmin && (
            <button
              className={activeView === "users" ? "active" : ""}
              onClick={() => setActiveView("users")}
            >
              Users
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
        <OfflineNotice />
        {isAdmin && <LowStockAlert />}

        {activeView === "dashboard" && isAdmin && <Dashboard />}

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

        {activeView === "sales" && <Sales user={user} />}

        {activeView === "customers" && isAdmin && <Customers />}

        {activeView === "inventory" && isAdmin && <Inventory />}

        {activeView === "restock" && isAdmin && (
          <RestockProduct user={user} onRestocked={refreshProducts} />
        )}

        {activeView === "reports" && isAdmin && <Reports />}

        {activeView === "audit" && isAdmin && <Audit />}

        {activeView === "users" && isAdmin && <Users currentUser={user} />}
      </main>
    </div>
  );
}

export default App;
