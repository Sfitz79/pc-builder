import { useState } from "react";

const USERS_KEY = "pctg_users";

function getUsers() {
  try { return JSON.parse(localStorage.getItem(USERS_KEY)) || {}; } catch { return {}; }
}

function saveUsers(users) {
  localStorage.setItem(USERS_KEY, JSON.stringify(users));
}

async function hashPassword(password) {
  const encoder = new TextEncoder();
  const data = encoder.encode(password);
  const hash = await crypto.subtle.digest("SHA-256", data);
  return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, "0")).join("");
}

export default function AuthModal({ mode = "login", onClose, onSwitch, onSuccess }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [name, setName] = useState("");
  const [error, setError] = useState("");

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");

    if (mode === "login") {
      const users = getUsers();
      const user = users[email];
      if (!user) {
        setError("No account found with this email.");
        return;
      }
      const hashed = await hashPassword(password);
      if (user.password !== hashed) {
        setError("Incorrect password.");
        return;
      }
      if (onSuccess) onSuccess({ email, name: user.name });
    } else {
      if (password !== confirmPassword) {
        setError("Passwords do not match.");
        return;
      }
      if (password.length < 6) {
        setError("Password must be at least 6 characters.");
        return;
      }
      const users = getUsers();
      if (users[email]) {
        setError("An account with this email already exists.");
        return;
      }
      const hashed = await hashPassword(password);
      users[email] = { name, password: hashed, createdAt: new Date().toISOString() };
      saveUsers(users);
      if (onSuccess) onSuccess({ email, name });
    }
  };

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-box" onClick={e => e.stopPropagation()}>
        <h2>{mode === "login" ? "Login" : "Register"}</h2>

        <form onSubmit={handleSubmit}>
          {mode === "signup" && (
            <div className="field">
              <label>Name</label>
              <input type="text" value={name} onChange={e => setName(e.target.value)} placeholder="Your name" required />
            </div>
          )}
          <div className="field">
            <label>E-mail Address</label>
            <input type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="you@example.com" required />
          </div>
          <div className="field">
            <label>Password</label>
            <input type="password" value={password} onChange={e => setPassword(e.target.value)} placeholder="Your password" required />
          </div>
          {mode === "signup" && (
            <div className="field">
              <label>Confirm Password</label>
              <input type="password" value={confirmPassword} onChange={e => setConfirmPassword(e.target.value)} placeholder="Confirm password" required />
            </div>
          )}

          {error && (
            <div style={{ padding: "8px 12px", background: "rgba(239,68,68,0.1)", borderRadius: "6px", border: "1px solid rgba(239,68,68,0.2)", color: "#ef4444", fontSize: "12px", marginBottom: "10px" }}>
              {error}
            </div>
          )}

          {mode === "signup" && (
            <div className="checkbox-field" style={{ display: "flex", alignItems: "center", gap: "8px", marginBottom: "14px" }}>
              <input type="checkbox" id="terms" required style={{ width: "auto" }} />
              <label htmlFor="terms" style={{ margin: 0, fontSize: "12px", color: "#888" }}>I agree to the Terms & Conditions</label>
            </div>
          )}

          <div className="actions">
            <button type="submit">{mode === "login" ? "Login" : "Register"}</button>
          </div>
        </form>

        <div className="or-divider" style={{ textAlign: "center", color: "#555", fontSize: "12px", margin: "14px 0" }}>or</div>

        <div className="social-login" style={{ display: "flex", gap: "8px" }}>
          <button type="button" className="secondary" style={{ flex: 1, fontSize: "12px" }} onClick={() => {
            if (onSuccess) onSuccess({ email: "guest@example.com", name: "Guest User" });
          }}>
            Continue as Guest
          </button>
        </div>

        <div className="switch-action" style={{ textAlign: "center", marginTop: "14px", fontSize: "13px", color: "#888" }}>
          {mode === "login" ? (
            <>Are you a new User? <a style={{ color: "#00eaff", cursor: "pointer" }} onClick={() => onSwitch("signup")}>Register</a> yourself.</>
          ) : (
            <>Already a user? <a style={{ color: "#00eaff", cursor: "pointer" }} onClick={() => onSwitch("login")}>Login here</a></>
          )}
        </div>
      </div>
    </div>
  );
}
