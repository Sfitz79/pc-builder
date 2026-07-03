import { useState, useRef, useEffect } from "react";
import { Link, useLocation } from "react-router-dom";
import AuthModal from "./AuthModal";
import { usePCStore } from "../store/usePCStore";
import { assetPath } from "../utils/assetPath";
import "./Navbar.css";

function useClickOutside(ref, handler) {
  useEffect(() => {
    function handleClick(e) {
      if (ref.current && !ref.current.contains(e.target)) handler();
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, [ref, handler]);
}

export default function Navbar() {
  const location = useLocation();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [toolsOpen, setToolsOpen] = useState(false);
  const [authOpen, setAuthOpen] = useState(false);
  const [authMode, setAuthMode] = useState("login");
  const [user, setUser] = useState(null);
  const [adminPrompt, setAdminPrompt] = useState(false);
  const [adminError, setAdminError] = useState("");
  const adminMode = usePCStore(s => s.adminMode);
  const setAdminMode = usePCStore(s => s.setAdminMode);
  const toolsRef = useRef(null);
  useClickOutside(toolsRef, () => setToolsOpen(false));

  useEffect(() => {
    const stored = sessionStorage.getItem("pctg_user");
    if (stored) {
      try { setUser(JSON.parse(stored)); } catch { /* ignore parse errors */ }
    }
  }, []);

  const isActive = (path) => location.pathname === path ? "active" : "";

  const handleLogout = () => {
    sessionStorage.removeItem("pctg_user");
    setUser(null);
  };

  const handleAuthSuccess = (userData) => {
    sessionStorage.setItem("pctg_user", JSON.stringify(userData));
    setUser(userData);
    setAuthOpen(false);
  };

  const handleAdminLogin = (username, password) => {
    const adminUser = import.meta.env.VITE_ADMIN_USER || "admin";
    const adminPw = import.meta.env.VITE_ADMIN_PASSWORD || "admin";
    if (username === adminUser && password === adminPw) {
      setAdminMode(true);
      setAdminPrompt(false);
      setAdminError("");
    } else {
      setAdminError("Invalid username or password");
    }
  };

  return (
    <nav className="navbar">
      <Link to="/" className="navbar-logo">
        <img src={assetPath("/pctg-logo.png")} alt="PcTechGuyOnline" style={{ height: "40px", width: "auto" }} />
      </Link>

      <ul className={`navbar-links${mobileOpen ? " mobile-open" : ""}`}>
        <li><Link to="/" className={isActive("/")}>Home</Link></li>
        <li><Link to="/builder" className={isActive("/builder")}>Builder</Link></li>
        <li>
          <div className="dropdown-wrapper" ref={toolsRef}>
            <button className="nav-dropdown-btn" onClick={() => setToolsOpen(!toolsOpen)}>
              Tools ▾
            </button>
            {toolsOpen && (
              <div className="dropdown-menu" onClick={() => setToolsOpen(false)}>
                <Link to="/ai-generator">AI Build Generator</Link>
                <Link to="/game-system-generator">Game System Generator</Link>
                <Link to="/fps-calculator">FPS Calculator</Link>
                <Link to="/bottleneck-calculator">Bottleneck Calculator</Link>
                <Link to="/compare">Component Comparison</Link>
                <Link to="/builds">Build History</Link>
              </div>
            )}
          </div>
        </li>
        <li><Link to="/summary" className={isActive("/summary")}>Summary</Link></li>
        <li><Link to="/admin" className={`admin-link ${isActive("/admin")}`}>⚙ Admin</Link></li>
      </ul>

      <div className="navbar-right">
        {user ? (
          <div className="user-info" style={{ display: "flex", alignItems: "center", gap: "10px" }}>
            <span style={{ fontSize: "12px", color: "#00eaff" }}>{user.email}</span>
            <button className="auth-btn login" onClick={handleLogout} style={{ padding: "6px 12px", fontSize: "12px" }}>
              Logout
            </button>
          </div>
        ) : (
          <>
            <button className="auth-btn login" onClick={() => { setAuthMode("login"); setAuthOpen(true); }}>
              Login
            </button>
            <button className="auth-btn signup" onClick={() => { setAuthMode("signup"); setAuthOpen(true); }}>
              Signup
            </button>
          </>
        )}

        <button onClick={() => adminMode ? setAdminMode(false) : setAdminPrompt(true)}
          style={{
            background: "none", border: `1px solid ${adminMode ? "#00eaff" : "rgba(255,255,255,0.15)"}`,
            color: adminMode ? "#00eaff" : "#666", borderRadius: "6px", padding: "6px 10px",
            cursor: "pointer", fontFamily: "inherit", fontSize: "13px",
            boxShadow: adminMode ? "0 0 8px rgba(0,234,255,0.3)" : "none"
          }}
          title={adminMode ? "Lock admin mode" : "Unlock admin mode"}>
          {adminMode ? "🔓 Admin" : "🔒"}
        </button>

        <button className="mobile-toggle" onClick={() => setMobileOpen(!mobileOpen)}>
          <svg viewBox="0 0 24 24" fill="none" stroke="#aaa" strokeWidth="2" strokeLinecap="round">
            <path d="M3 12h18M3 6h18M3 18h18"/>
          </svg>
        </button>
      </div>

      {adminPrompt && (
        <div className="modal-overlay" onClick={() => { setAdminPrompt(false); setAdminError(""); }}>
          <div className="modal-box" style={{ maxWidth: "360px" }} onClick={e => e.stopPropagation()}>
            <h2 style={{ marginTop: 0 }}>Admin Access</h2>
            <p style={{ fontSize: "13px", color: "#aaa", marginBottom: "12px" }}>Enter admin credentials to unlock prices and URLs.</p>
            <form onSubmit={e => { e.preventDefault(); const d = new FormData(e.target); handleAdminLogin(d.get("user"), d.get("pw")); }}>
              <input name="user" type="text" placeholder="Username"
                style={{
                  width: "100%", padding: "10px 12px", borderRadius: "6px", border: "1px solid rgba(255,255,255,0.15)",
                  background: "#0d0d18", color: "#e6e6e6", fontFamily: "inherit", fontSize: "14px",
                  boxSizing: "border-box", marginBottom: "8px"
                }}
                autoFocus />
              <input name="pw" type="password" placeholder="Password"
                style={{
                  width: "100%", padding: "10px 12px", borderRadius: "6px", border: "1px solid rgba(255,255,255,0.15)",
                  background: "#0d0d18", color: "#e6e6e6", fontFamily: "inherit", fontSize: "14px",
                  boxSizing: "border-box", marginBottom: "8px"
                }}
                autoFocus />
              {adminError && <div style={{ color: "#ef4444", fontSize: "12px", marginBottom: "8px" }}>{adminError}</div>}
              <div className="actions">
                <button type="submit" className="button" style={{ padding: "8px 20px", fontSize: "13px" }}>Unlock</button>
                <button type="button" className="button secondary" onClick={() => { setAdminPrompt(false); setAdminError(""); }}>Cancel</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {authOpen && (
        <AuthModal
          mode={authMode}
          onClose={() => setAuthOpen(false)}
          onSwitch={setAuthMode}
          onSuccess={handleAuthSuccess}
        />
      )}
    </nav>
  );
}
