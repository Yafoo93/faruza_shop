import React, { useState } from "react";
import api from "../services/api";

function Login({ onLogin }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setIsLoading(true);
    setError("");

    try {
      const res = await api.post("/login", { email, password });
      localStorage.setItem("token", res.data.token);
      onLogin(res.data.user);
    } catch (err) {
      if (!err.response) {
        setError("Could not reach the API. Check that Laravel and MySQL are running.");
      } else if (err.response.status === 422 || err.response.status === 401) {
        setError("Invalid credentials");
      } else {
        setError("Login failed. Check the API server logs.");
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <main className="login-page">
      <section className="login-panel">
        <p className="eyebrow">F mart</p>
        <h1>Sign in</h1>
        <p className="muted">Use your shop owner or cashier account.</p>

        <form className="form-stack" onSubmit={handleSubmit}>
          <label>
            Email
            <input
              type="email"
              placeholder="Email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </label>
          <label>
            Password
            <input
              type="password"
              placeholder="Password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </label>
          <button className="primary-button" type="submit" disabled={isLoading}>
            {isLoading ? "Signing in..." : "Login"}
          </button>
        </form>

        {error && <p className="form-error">{error}</p>}
      </section>
    </main>
  );
}

export default Login;
