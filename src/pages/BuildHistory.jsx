import { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import { usePCStore } from "../store/usePCStore";
import { getBuildHistory, deleteBuildFromHistory, clearBuildHistory } from "../utils/buildHistory";
import { BUILDER_CATEGORIES } from "../utils/builderConfig";

export default function BuildHistory() {
  const [builds, setBuilds] = useState([]);
  const setComponent = usePCStore(s => s.setComponent);

  useEffect(() => {
    setBuilds(getBuildHistory());
  }, []);

  const refresh = () => setBuilds(getBuildHistory());

  const handleDelete = (id) => {
    if (!confirm("Delete this build?")) return;
    deleteBuildFromHistory(id);
    refresh();
  };

  const handleLoad = (build) => {
    usePCStore.getState().resetBuild();
    for (const [cat, item] of Object.entries(build.selections)) {
      setComponent(cat, item);
    }
  };

  const handleClearAll = () => {
    if (!confirm("Clear all saved builds?")) return;
    clearBuildHistory();
    refresh();
  };

  const handleExport = (build) => {
    const json = JSON.stringify(build, null, 2);
    const blob = new Blob([json], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${build.name.replace(/[^a-z0-9]/gi, "_")}.json`;
    a.click();
    URL.revokeObjectURL(url);
  };

  const handleImport = () => {
    const input = document.createElement("input");
    input.type = "file";
    input.accept = ".json";
    input.onchange = (e) => {
      const file = e.target.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (ev) => {
        try {
          const build = JSON.parse(ev.target.result);
          if (build.selections) {
            const history = getBuildHistory();
            history.unshift({ ...build, id: Date.now().toString(36), createdAt: new Date().toISOString() });
            localStorage.setItem("pctg-build-history", JSON.stringify(history.slice(0, 50)));
            refresh();
          }
        } catch { alert("Invalid build file"); }
      };
      reader.readAsText(file);
    };
    input.click();
  };

  return (
    <div style={{ maxWidth: "900px", margin: "0 auto" }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "20px", flexWrap: "wrap", gap: "10px" }}>
        <div>
          <h1 style={{ fontSize: "22px", color: "#00eaff", textTransform: "uppercase", letterSpacing: "1px" }}>
            Build History
          </h1>
          <div style={{ fontSize: "13px", color: "#888", marginTop: "4px" }}>
            {builds.length} saved build{builds.length !== 1 ? "s" : ""}
          </div>
        </div>
        <div style={{ display: "flex", gap: "8px" }}>
          <button className="button secondary small" onClick={handleImport}>Import</button>
          {builds.length > 0 && (
            <button className="button danger small" onClick={handleClearAll}>Clear All</button>
          )}
        </div>
      </div>

      {builds.length === 0 ? (
        <div style={{ textAlign: "center", padding: "60px 20px", background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.1)" }}>
          <div style={{ fontSize: "40px", marginBottom: "12px", opacity: 0.3 }}>📦</div>
          <h3 style={{ color: "#888", marginBottom: "8px" }}>No Saved Builds</h3>
          <p style={{ color: "#555", fontSize: "13px", marginBottom: "16px" }}>
            Builds you save from the PC Builder will appear here.
          </p>
          <Link to="/builder" className="button" style={{ textDecoration: "none" }}>
            Start Building
          </Link>
        </div>
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: "12px" }}>
          {builds.map(build => {
            const count = Object.keys(build.selections || {}).length;
            const catLabels = Object.keys(build.selections || {}).map(id => {
              const cat = BUILDER_CATEGORIES.find(c => c.id === id);
              return cat?.label || id;
            });
            return (
              <div key={build.id} style={{
                background: "#0d0d18", borderRadius: "10px",
                border: "1px solid rgba(0,234,255,0.1)", padding: "16px",
                display: "flex", alignItems: "center", gap: "16px", flexWrap: "wrap"
              }}>
                <div style={{ flex: 1, minWidth: "200px" }}>
                  <div style={{ fontSize: "15px", fontWeight: 700, color: "#e6e6e6" }}>{build.name}</div>
                  {build.description && (
                    <div style={{ fontSize: "12px", color: "#666", marginTop: "2px" }}>{build.description}</div>
                  )}
                  <div style={{ display: "flex", gap: "8px", marginTop: "6px", flexWrap: "wrap" }}>
                    <span style={{ fontSize: "11px", color: "#555" }}>
                      {count} component{count !== 1 ? "s" : ""}
                    </span>
                    <span style={{ fontSize: "11px", color: "#555" }}>
                      {new Date(build.createdAt).toLocaleDateString()}
                    </span>
                    {catLabels.slice(0, 4).map((l, i) => (
                      <span key={i} style={{
                        fontSize: "10px", padding: "2px 8px", borderRadius: "3px",
                        background: "rgba(0,234,255,0.08)", color: "#00eaff"
                      }}>{l}</span>
                    ))}
                    {catLabels.length > 4 && (
                      <span style={{ fontSize: "10px", color: "#555" }}>+{catLabels.length - 4} more</span>
                    )}
                  </div>
                </div>
                <div style={{ display: "flex", gap: "6px", flexShrink: 0 }}>
                  <Link to="/builder" className="button small" style={{ textDecoration: "none", padding: "6px 14px", fontSize: "11px" }}
                    onClick={() => handleLoad(build)}>
                    Load
                  </Link>
                  <button className="button secondary small" style={{ padding: "6px 10px", fontSize: "11px" }}
                    onClick={() => handleExport(build)}>
                    Export
                  </button>
                  <button className="button danger small" style={{ padding: "6px 10px", fontSize: "11px" }}
                    onClick={() => handleDelete(build.id)}>
                    Delete
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
