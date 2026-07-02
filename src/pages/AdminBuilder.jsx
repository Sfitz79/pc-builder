import { useState } from "react";
import { usePCStore } from "../store/usePCStore";
import { checkCompatibility, estimateRequiredWattage } from "../utils/compatibility";
import { BUILDER_CATEGORIES, SUBCATEGORY_GROUPS, getComponentIconSVG } from "../utils/builderConfig";
import ComponentSelector from "../components/ComponentSelector";

const ADMIN_USER = "Pctg";
const ADMIN_PASS = "22.techguy.23";

function checkAuth() {
  return sessionStorage.getItem("admin_auth") === "true";
}

function LoginGate({ onLogin }) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");

  const handleSubmit = (e) => {
    e.preventDefault();
    if (username === ADMIN_USER && password === ADMIN_PASS) {
      sessionStorage.setItem("admin_auth", "true");
      onLogin();
    } else {
      setError("Invalid username or password");
    }
  };

  return (
    <div style={{ display: "flex", alignItems: "center", justifyContent: "center", minHeight: "50vh" }}>
      <div className="modal-box" style={{ maxWidth: "380px" }}>
        <h2 style={{ textAlign: "center" }}>Admin Login</h2>
        <form onSubmit={handleSubmit}>
          <div className="field">
            <label>Username</label>
            <input type="text" value={username} onChange={e => setUsername(e.target.value)} placeholder="Enter username" required autoFocus />
          </div>
          <div className="field">
            <label>Password</label>
            <input type="password" value={password} onChange={e => setPassword(e.target.value)} placeholder="Enter password" required />
          </div>
          {error && <p style={{ color: "#e74c3c", fontSize: "13px", margin: "0 0 10px" }}>{error}</p>}
          <div className="actions">
            <button type="submit">Login</button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function AdminBuilder() {
  const [authed, setAuthed] = useState(checkAuth);

  const selections = usePCStore(s => s.selections);
  const setComponent = usePCStore(s => s.setComponent);
  const mandatory = usePCStore(s => s.mandatory);
  const getComponentsTotal = usePCStore(s => s.getComponentsTotal);
  const getBundledPrice = usePCStore(s => s.getBundledPrice);
  const resetBuild = usePCStore(s => s.resetBuild);

  const [selectorCategory, setSelectorCategory] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);

  if (!authed) {
    return <LoginGate onLogin={() => setAuthed(true)} />;
  }

  const issues = checkCompatibility(selections);
  const wattage = estimateRequiredWattage(selections.cpu, selections.gpu);
  const componentsTotal = getComponentsTotal();
  const bundledPrice = getBundledPrice();
  const hasComponents = Object.keys(selections).length > 0;

  const renderTableRow = (category) => {
    const item = selections[category.id];
    return (
      <tr key={category.id}>
        <td className="component-icon-cell">
          <img src={getComponentIconSVG(category.icon)} alt="" />
        </td>
        <td className="component-name-cell">{category.label}</td>
        <td className="component-item-cell">
          {item ? (
            <div className="item-selected">
              <span className="item-name">{item.name}</span>
              <div className="item-detail">
                {item.socket && <span>Socket: {item.socket} </span>}
                {item.speed && <span>Speed: {item.speed} </span>}
                {item.memory && <span>VRAM: {item.memory}GB </span>}
                {item.wattage && <span>{item.wattage}W </span>}
                {item.core_count && <span>{item.core_count}Cores </span>}
              </div>
            </div>
          ) : (
            <span className="item-placeholder">—</span>
          )}
        </td>
        <td className="component-price-cell">
          {item ? `£${parseFloat(item.price).toFixed(2)}` : "—"}
        </td>
        <td className="component-action-cell">
          <button className="choose-btn" onClick={() => setSelectorCategory(category)}>
            {item ? "Change" : `+ Choose ${category.label}`}
          </button>
          {item && (
            <button
              onClick={(e) => { e.stopPropagation(); setDeleteTarget(category.id); }}
              style={{ marginLeft: "6px", padding: "8px 10px", background: "none", border: "1px solid #e74c3c", color: "#e74c3c", borderRadius: "6px", cursor: "pointer", fontSize: "12px" }}
              title="Remove"
            >
              ✕
            </button>
          )}
        </td>
      </tr>
    );
  };

  return (
    <div className="builder-layout">
      <div className="builder-main">
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "16px", flexWrap: "wrap", gap: "10px" }}>
          <div>
            <h1 style={{ fontSize: "22px", color: "#ff005e", textTransform: "uppercase", letterSpacing: "1px", textShadow: "0 0 10px rgba(255,0,94,0.3)", margin: 0 }}>
              Admin Panel
            </h1>
            <div style={{ fontSize: "12px", color: "#ff005e", marginTop: "4px", opacity: 0.7 }}>
              Price Breakdown View
            </div>
          </div>
          <div style={{ display: "flex", gap: "8px" }}>
            <button className="button secondary small" onClick={resetBuild}>
              Reset Build
            </button>
          </div>
        </div>

        <table className="builder-table">
          <thead>
            <tr>
              <th style={{ width: "40px" }}></th>
              <th>Component</th>
              <th>Product</th>
              <th style={{ width: "100px" }}>Price</th>
              <th style={{ width: "150px" }}>Action</th>
            </tr>
          </thead>
          <tbody>
            {BUILDER_CATEGORIES.map(renderTableRow)}
          </tbody>
        </table>

        {SUBCATEGORY_GROUPS.map(group => (
          <div key={group.label} className="subcategory-group">
            <div className="subcategory-label">{group.label}</div>
            <table className="builder-table">
              <thead>
                <tr>
                  <th style={{ width: "40px" }}></th>
                  <th>Component</th>
                  <th>Product</th>
                  <th style={{ width: "100px" }}>Price</th>
                  <th style={{ width: "150px" }}>Action</th>
                </tr>
              </thead>
              <tbody>
                {group.categories.map(renderTableRow)}
              </tbody>
            </table>
          </div>
        ))}
      </div>

      <div className="builder-sidebar">
        <div className="sidebar-card">
          <div className="card-label">Compatibility</div>
          <div className={`card-value ${issues.length === 0 ? "good" : "warning"}`}>
            {issues.length === 0 ? "No Issues" : `${issues.length} Issue(s)`}
          </div>
          {issues.length > 0 && (
            <ul style={{ margin: "8px 0 0", paddingLeft: "16px", fontSize: "12px", color: "#f59e0b", lineHeight: "1.6" }}>
              {issues.map((msg, i) => <li key={i}>{msg}</li>)}
            </ul>
          )}
        </div>

        {wattage > 0 && (
          <div className="sidebar-card">
            <div className="card-label">Estimated Wattage</div>
            <div className="card-value">{wattage}W</div>
          </div>
        )}

        <div className="sidebar-card">
          <div className="card-label">Price Breakdown</div>
          <div style={{ fontSize: "13px", color: "#aaa", lineHeight: "2" }}>
            <div style={{ display: "flex", justifyContent: "space-between" }}>
              <span>Components Total</span>
              <span style={{ color: "#e6e6e6" }}>£{componentsTotal.toFixed(2)}</span>
            </div>
            {Object.values(mandatory).map(m => (
              <div key={m.name} style={{ display: "flex", justifyContent: "space-between" }}>
                <span style={{ fontSize: "12px" }}>{m.name}</span>
                <span style={{ color: "#e6e6e6" }}>£{parseFloat(m.price).toFixed(2)}</span>
              </div>
            ))}
            <div style={{ borderTop: "1px solid rgba(255,255,255,0.1)", marginTop: "4px", paddingTop: "4px", display: "flex", justifyContent: "space-between", fontWeight: 700, color: "#00eaff" }}>
              <span>Bundled Total</span>
              <span>£{bundledPrice.toLocaleString('en-GB')}</span>
            </div>
          </div>
        </div>

        {hasComponents && (
          <div className="sidebar-actions">
            <button className="button" style={{ background: "linear-gradient(135deg, #ff005e, #00eaff)", fontSize: "13px" }}>
              Buy all at once
            </button>
          </div>
        )}
      </div>

      {selectorCategory && (
        <ComponentSelector
          category={selectorCategory}
          selections={selections}
          selectedItem={selections[selectorCategory.id]}
          onSelect={(item) => setComponent(selectorCategory.id, item)}
          onClose={() => setSelectorCategory(null)}
          showPrices={true}
        />
      )}

      {deleteTarget && (
        <div className="modal-overlay" onClick={() => setDeleteTarget(null)}>
          <div className="modal-box" onClick={e => e.stopPropagation()}>
            <h2>Are you sure?</h2>
            <p style={{ color: "#aaa", fontSize: "14px" }}>Do you really want to delete? This process cannot be undone.</p>
            <div className="actions">
              <button className="danger" onClick={() => { usePCStore.getState().clearComponent(deleteTarget); setDeleteTarget(null); }}>
                Delete
              </button>
              <button className="secondary" onClick={() => setDeleteTarget(null)}>Cancel</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
