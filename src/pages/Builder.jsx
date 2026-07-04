import { useState } from "react";
import { Link } from "react-router-dom";
import { usePCStore } from "../store/usePCStore";
import { checkCompatibility, estimateRequiredWattage } from "../utils/compatibility";
import { BUILDER_CATEGORIES, SUBCATEGORY_GROUPS, getComponentIconSVG } from "../utils/builderConfig";
import ComponentSelector from "../components/ComponentSelector";
import SaveBuildModal from "../components/SaveBuildModal";
import { PriceBreakdownPair } from "../components/PriceBreakdown";
import { saveBuildToHistory } from "../utils/buildHistory";

export default function Builder() {
  const selections = usePCStore(s => s.selections);
  const setComponent = usePCStore(s => s.setComponent);
  const getBundledPrice = usePCStore(s => s.getBundledPrice);
  const resetBuild = usePCStore(s => s.resetBuild);

  const [selectorCategory, setSelectorCategory] = useState(null);
  const [showSaveModal, setShowSaveModal] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);

  const adminMode = usePCStore(s => s.adminMode);
  const buildType = usePCStore(s => s.buildType);
  const setBuildType = usePCStore(s => s.setBuildType);
  const issues = checkCompatibility(selections);
  const wattage = estimateRequiredWattage(selections.cpu, selections.gpu);
  const bundledPrice = getBundledPrice();
  const REQUIRED = ["case", "case-fan", "cooler", "cpu", "motherboard", "ram", "storage", "psu", "os"];
  const cpu = selections.cpu;
  const gpuNeeded = !cpu || !cpu.integrated_graphics || cpu.integrated_graphics.toLowerCase() === "none";
  const allRequired = (gpuNeeded ? [...REQUIRED, "gpu"] : REQUIRED).every(cat => selections[cat]);
  const partsOnlyPrice = (() => {
    const compTotal = Object.entries(selections).reduce((sum, [cat, item]) => {
      const p = parseFloat(item?.price);
      return sum + (isNaN(p) ? 0 : p);
    }, 0);
    return compTotal + 70;
  })();

  const handleSave = (data) => {
    saveBuildToHistory(data.name, selections, data.description);
    const json = JSON.stringify({ selections: { ...selections }, name: data.name, description: data.description });
    const base64 = btoa(encodeURIComponent(json));
    const shareUrl = `${window.location.origin}${window.location.pathname}#/builder?build=${base64}`;
    navigator.clipboard.writeText(shareUrl).catch(() => {});
    setShowSaveModal(false);
  };

  const copyMarkup = (_type) => {
    const rows = [];
    for (const cat of BUILDER_CATEGORIES) {
      const item = selections[cat.id];
      rows.push({ label: cat.label, name: item ? item.name : "—" });
    }
    for (const group of SUBCATEGORY_GROUPS) {
      for (const cat of group.categories) {
        const item = selections[cat.id];
        if (item) rows.push({ label: cat.label, name: item.name });
      }
    }

    let output = "";
    switch (_type) {
      case "reddit":
        output = rows.map(r => `**${r.label}:** ${r.name}`).join("\n\n");
        break;
      case "bb":
        output = rows.map(r => `[b]${r.label}:[/b] ${r.name}`).join("\n");
        break;
      case "code":
        output = "```\n" + rows.map(r => `${r.label}: ${r.name}`).join("\n") + "\n```";
        break;
      case "text":
        output = rows.map(r => `${r.label}: ${r.name}`).join(" | ");
        break;
      default:
        output = rows.map(r => `${r.label}: ${r.name}`).join("\n");
    }
    navigator.clipboard.writeText(output).catch(() => {});
  };

  const renderTableRow = (category) => {
    const item = selections[category.id];
    const itemName = item?.name || "";
    const displayBrand = itemName.split(" ")[0] || "";
    const modelName = itemName.substring(displayBrand.length).trim() || itemName;
    return (
      <tr key={category.id}>
        <td className="component-icon-cell">
          <img src={getComponentIconSVG(category.icon)} alt="" />
        </td>
        <td className="component-name-cell">{category.label}</td>
        <td className="component-item-cell">
          {item ? (
            <div className="item-selected">
              <span className="item-name">
                <span style={{
                  display: "inline-block", padding: "1px 6px", borderRadius: "3px",
                  background: "rgba(0,234,255,0.1)", color: "#00eaff",
                  fontSize: "11px", fontWeight: 700, marginRight: "6px"
                }}>
                  {displayBrand}
                </span>{' '}
                <span>{modelName}</span>
              </span>
              <div className="item-detail">
                {item.socket && <span>Socket: {item.socket} </span>}
                {item.speed && category.id !== "ram" && <span>Speed: {item.speed} </span>}
                {category.id === "ram" && <span>Speed: {(String(item.speed).length > 2 ? item.speed : item.modules) || item.speed}MHz </span>}
                {category.id === "ram" && item.ram_type && <span>Type: {item.ram_type} </span>}
                {category.id === "ram" && !item.ram_type && item.speed && /^\d{1,2}$/.test(String(item.speed)) && <span>Type: DDR{item.speed} </span>}
                {item.memory && <span>VRAM: {item.memory}GB </span>}
                {item.wattage && <span>{item.wattage}W </span>}
                {item.core_count && <span>{item.core_count}Cores </span>}
              </div>
            </div>
          ) : (
            <span className="item-placeholder">—</span>
          )}
        </td>
        <td className="component-from-cell">
          {item ? "PCTG" : "—"}
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
          <h1 style={{ fontSize: "22px", color: "#00eaff", textTransform: "uppercase", letterSpacing: "1px", textShadow: "0 0 10px rgba(0,234,255,0.3)" }}>
            PC Builder
          </h1>
          <div style={{ display: "flex", gap: "8px" }}>
            <button className="button secondary small" onClick={() => setShowSaveModal(true)} disabled={Object.keys(selections).length <= 1}>
              Save As
            </button>
            <button className="button secondary small" onClick={resetBuild}>
              Start New
            </button>
          </div>
        </div>

        <table className="builder-table">
          <thead>
            <tr>
              <th style={{ width: "40px" }}></th>
              <th>Component</th>
              <th>Product</th>
              <th>From</th>
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
                  <th>From</th>
                  <th style={{ width: "150px" }}>Action</th>
                </tr>
              </thead>
              <tbody>
                {group.categories.map(renderTableRow)}
              </tbody>
            </table>
          </div>
        ))}

        <div style={{ marginTop: "12px", padding: "10px 16px", background: "rgba(255,255,255,0.02)", borderRadius: "8px", fontSize: "12px", color: "#555" }}>
          Note: Components are saved in Cookies. It will be removed if cookies are cleared from your browser.
          Make sure you Log In and Save your build.
        </div>
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

        <div className="sidebar-card" style={{ padding: "12px" }}>
          <PriceBreakdownPair selections={selections} adminMode={adminMode} />
        </div>

        {allRequired && (
          <div className="sidebar-actions">
            <Link to="/summary" className="button" style={{ background: "linear-gradient(135deg, #00eaff, #ff005e)", fontSize: "13px", textDecoration: "none" }}>
              View Summary →
            </Link>
            <button className="button secondary" style={{ fontSize: "12px" }}>
              Buy all at once
            </button>
          </div>
        )}

        <div className="sidebar-card">
          <div className="card-label">Markup</div>
          <div className="markup-toolbar">
            <button onClick={() => copyMarkup("copy")} title="Copy">📋 Copy</button>
            <button onClick={() => copyMarkup("reddit")} title="Reddit">🔴 Reddit</button>
            <button onClick={() => copyMarkup("code")} title="Code">&lt;/&gt; Code</button>
            <button onClick={() => copyMarkup("text")} title="Text">TXT</button>
            <button onClick={() => copyMarkup("bb")} title="BBCode">BB</button>
          </div>
        </div>

        <div className="sidebar-card" style={{ textAlign: "center" }}>
          <div style={{ fontSize: "12px", color: "#555" }}>
            Have A Question?
          </div>
          <div style={{ fontSize: "12px", color: "#00eaff", marginTop: "4px", cursor: "pointer" }}>
            Contact Us
          </div>
        </div>
      </div>

      {selectorCategory && (
        <ComponentSelector
          category={selectorCategory}
          selections={selections}
          selectedItem={selections[selectorCategory.id]}
          onSelect={(item) => setComponent(selectorCategory.id, item)}
          onClose={() => setSelectorCategory(null)}
          showPrices={false}
        />
      )}

      {showSaveModal && (
        <SaveBuildModal
          onClose={() => setShowSaveModal(false)}
          onSave={handleSave}
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
