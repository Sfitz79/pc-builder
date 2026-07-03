import { useState, useEffect, useMemo, useCallback } from "react";
import { usePCStore } from "../store/usePCStore";
import { generateBuild } from "../utils/aiBuildGenerator";
import { GAME_REQUIREMENTS, GAME_CATEGORIES } from "../utils/partsKnowledgeBase";
import { BUILDER_CATEGORIES, SUBCATEGORY_GROUPS } from "../utils/builderConfig";

const GAME_LIST = Object.entries(GAME_REQUIREMENTS).map(([id, game]) => ({
  id, name: game.name, icon: game.icon || "🎮", category: game.category || "fps"
}));

const CATEGORY_KEYS = Object.keys(GAME_CATEGORIES);

const TIERS = [
  { key: "min", label: "Minimum Spec", desc: "Meets minimum requirements", budget: 600, budgetLabel: "£600" },
  { key: "rec", label: "Recommended", desc: "Meets recommended requirements", budget: 1200, budgetLabel: "£1,200" },
  { key: "above1", label: "Well Above #1", desc: "High-end — exceeds recommended", budget: 2000, budgetLabel: "£2,000" },
  { key: "above2", label: "Well Above #2", desc: "Enthusiast — premium performance", budget: 3500, budgetLabel: "£3,500" },
  { key: "above3", label: "Well Above #3", desc: "No compromise — ultimate build", budget: 5500, budgetLabel: "£5,500" },
];

export default function GameSystemGenerator() {
  const [search, setSearch] = useState("");
  const [activeGameCategory, setActiveGameCategory] = useState("fps");
  const [selectedGameIds, setSelectedGameIds] = useState([]);
  const [generating, setGenerating] = useState(false);
  const [results, setResults] = useState(null);
  const [error, setError] = useState("");
  const [activeGameTab, setActiveGameTab] = useState(0);
  const [activeTierTabs, setActiveTierTabs] = useState({});
  const setComponent = usePCStore(s => s.setComponent);

  const filteredGames = useMemo(() => {
    if (!search.trim()) return GAME_LIST.filter(g => g.category === activeGameCategory);
    const q = search.toLowerCase();
    return GAME_LIST.filter(g => g.name.toLowerCase().includes(q));
  }, [search, activeGameCategory]);

  const toggleGame = (gameId) => {
    setSelectedGameIds(prev => {
      if (prev.includes(gameId)) return prev.filter(id => id !== gameId);
      if (prev.length >= 5) return prev;
      return [...prev, gameId];
    });
  };

  const selectedGames = useMemo(() => {
    return selectedGameIds.map(id => ({ id, ...GAME_REQUIREMENTS[id] }));
  }, [selectedGameIds]);

  const totalExpected = selectedGames.length * TIERS.length;

  const handleGenerate = useCallback(async () => {
    if (selectedGames.length === 0) return;
    setGenerating(true);
    setError("");
    setResults(null);
    setActiveGameTab(0);
    setActiveTierTabs({});

    const gameResults = [];

    for (const game of selectedGames) {
      const gameTiers = [];
      for (const tier of TIERS) {
        try {
          const build = await generateBuild(tier.budget, "gaming", "any", {
            needMonitor: false, needMouse: true, needKeyboard: true,
            needSpeakers: false, needWifi: false, consumerOnly: true, dualStorage: true
          });
          gameTiers.push({ tier, build });
        } catch {
          gameTiers.push({ tier, build: null });
        }
      }
      gameResults.push({ game, tiers: gameTiers });
    }

    setResults(gameResults);
    if (gameResults.length > 0) {
      const tabs = {};
      gameResults.forEach((_, i) => { tabs[i] = 0; });
      setActiveTierTabs(tabs);
    }
    setGenerating(false);
  }, [selectedGames]);

  useEffect(() => {
    if (results && results.length > 0) {
      setActiveGameTab(0);
      const tabs = {};
      results.forEach((_, i) => { tabs[i] = 0; });
      setActiveTierTabs(tabs);
    }
  }, [results]);

  const totalPrice = (build) => {
    if (!build) return 0;
    return Object.values(build).reduce((sum, item) => sum + (parseFloat(item.price) || 0), 0);
  };

  const handleApplyBuild = (build) => {
    if (!build) return;
    usePCStore.getState().resetBuild();
    for (const [cat, item] of Object.entries(build)) {
      setComponent(cat, item);
    }
  };

  function BuildTable({ build }) {
    if (!build) return <div style={{ padding: "20px", textAlign: "center", color: "#ef4444" }}>Failed to generate build for this tier.</div>;
    return (
      <table className="builder-table" style={{ marginBottom: 0, border: "none" }}>
        <thead>
          <tr>
            <th style={{ width: "40px" }}></th>
            <th>Component</th>
            <th>Product</th>
          </tr>
        </thead>
        <tbody>
          {BUILDER_CATEGORIES.map(cat => {
            const item = build[cat.id];
            return (
              <tr key={cat.id}>
                <td className="component-icon-cell">
                  <img src={`data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" stroke="%2300eaff" stroke-width="2"><rect x="4" y="4" width="40" height="40" rx="4"/><circle cx="24" cy="24" r="4"/></svg>`)}`} alt="" style={{ width: "24px", height: "24px", opacity: 0.5 }} />
                </td>
                <td style={{ fontWeight: 600, color: "#ccc" }}>{cat.label}</td>
                <td>
                  {item ? (
                    <span style={{ color: "#00eaff", fontWeight: 600, fontSize: "13px" }}>{item.name}</span>
                  ) : (
                    <span style={{ color: "#444" }}>—</span>
                  )}
                </td>
              </tr>
            );
          })}
          {SUBCATEGORY_GROUPS.flatMap(g => g.categories).map(cat => {
            const item = build[cat.id];
            if (!item) return null;
            return (
              <tr key={cat.id}>
                <td className="component-icon-cell">
                  <img src={`data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" stroke="%2300eaff" stroke-width="2"><rect x="4" y="4" width="40" height="40" rx="4"/><circle cx="24" cy="24" r="4"/></svg>`)}`} alt="" style={{ width: "24px", height: "24px", opacity: 0.5 }} />
                </td>
                <td style={{ fontWeight: 600, color: "#ccc" }}>{cat.label}</td>
                <td>
                  <span style={{ color: "#00eaff", fontWeight: 600, fontSize: "13px" }}>{item.name}</span>
                </td>
              </tr>
            );
          })}
          {build.storage_hdd && (
            <tr key="storage-hdd">
              <td className="component-icon-cell">
                <img src={`data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" stroke="%2300eaff" stroke-width="2"><rect x="4" y="4" width="40" height="40" rx="4"/><circle cx="24" cy="24" r="4"/></svg>`)}`} alt="" style={{ width: "24px", height: "24px", opacity: 0.5 }} />
              </td>
              <td style={{ fontWeight: 600, color: "#ccc" }}>Storage (Mass / HDD)</td>
              <td>
                <span style={{ color: "#00eaff", fontWeight: 600, fontSize: "13px" }}>{build.storage_hdd.name}</span>
              </td>
            </tr>
          )}
        </tbody>
      </table>
    );
  }

  return (
    <div style={{ maxWidth: "1000px", margin: "0 auto" }}>
      <h1 style={{ fontSize: "24px", color: "#00eaff", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "6px" }}>
        Game System Generator
      </h1>
      <p style={{ color: "#888", fontSize: "13px", marginBottom: "24px" }}>
        Select up to 5 games and we'll generate 5 PC builds for each — from minimum requirements to ultimate no-compromise systems.
      </p>

      <div style={{ background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.15)", padding: "20px", marginBottom: "24px" }}>
        <div style={{ marginBottom: "12px", display: "flex", gap: "8px", flexWrap: "wrap" }}>
          {CATEGORY_KEYS.map(cat => {
            const catInfo = GAME_CATEGORIES[cat];
            const count = GAME_LIST.filter(g => g.category === cat).length;
            return (
              <button key={cat} onClick={() => { setActiveGameCategory(cat); setSearch(""); }}
                style={{
                  padding: "6px 14px", borderRadius: "20px", border: "1px solid",
                  borderColor: activeGameCategory === cat ? catInfo.color : "rgba(255,255,255,0.1)",
                  background: activeGameCategory === cat ? `${catInfo.color}22` : "transparent",
                  color: activeGameCategory === cat ? catInfo.color : "#888",
                  cursor: "pointer", fontFamily: "inherit", fontSize: "12px",
                  transition: "all 0.15s", whiteSpace: "nowrap"
                }}
              >
                {catInfo.icon} {catInfo.label} ({count})
              </button>
            );
          })}
        </div>

        <div style={{ marginBottom: "12px" }}>
          <input
            type="text"
            placeholder={search ? "Search across all games..." : `Search ${GAME_CATEGORIES[activeGameCategory]?.label || "games"}...`}
            value={search}
            onChange={e => setSearch(e.target.value)}
            style={{
              width: "100%", padding: "10px 14px", borderRadius: "8px", border: "1px solid rgba(255,255,255,0.1)",
              background: "#0a0a0f", color: "#e6e6e6", fontFamily: "inherit", fontSize: "14px",
              outline: "none", boxSizing: "border-box"
            }}
          />
        </div>

        <div style={{ maxHeight: "260px", overflowY: "auto", marginBottom: "12px" }}>
          {filteredGames.map(game => {
            const checked = selectedGameIds.includes(game.id);
            const disabled = !checked && selectedGameIds.length >= 5;
            const catInfo = GAME_CATEGORIES[game.category];
            return (
              <label key={game.id} onClick={() => { if (!disabled) toggleGame(game.id); }}
                style={{
                  display: "flex", alignItems: "center", gap: "10px", padding: "8px 12px",
                  borderRadius: "6px", cursor: disabled ? "not-allowed" : "pointer",
                  background: checked ? "rgba(0,234,255,0.06)" : "transparent",
                  border: checked ? "1px solid rgba(0,234,255,0.2)" : "1px solid transparent",
                  marginBottom: "2px", opacity: disabled ? 0.4 : 1,
                  transition: "all 0.15s", userSelect: "none"
                }}
              >
                <input type="checkbox" checked={checked} disabled={disabled}
                  onChange={() => {}} style={{ accentColor: "#00eaff" }} />
                <span style={{ fontSize: "18px" }}>{game.icon}</span>
                <span style={{ fontSize: "13px", color: "#ccc", flex: 1 }}>{game.name}</span>
                {!search && (
                  <span style={{ fontSize: "10px", color: catInfo?.color || "#555", opacity: 0.6 }}>
                    {catInfo?.label}
                  </span>
                )}
              </label>
            );
          })}
          {filteredGames.length === 0 && (
            <div style={{ textAlign: "center", padding: "20px", color: "#555", fontSize: "13px" }}>
              No games found {search ? `matching "${search}"` : "in this category"}
            </div>
          )}
        </div>

        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <span style={{ fontSize: "12px", color: "#888" }}>
            {selectedGameIds.length}/5 games selected
            {selectedGameIds.length > 0 && <span> • {totalExpected} builds to generate</span>}
          </span>
          <div style={{ display: "flex", gap: "8px" }}>
            {selectedGameIds.length > 0 && (
              <button className="button secondary small" onClick={() => setSelectedGameIds([])}>
                Clear All
              </button>
            )}
            <button onClick={handleGenerate} disabled={selectedGameIds.length === 0 || generating}
              className="button" style={{ padding: "10px 24px", fontSize: "14px" }}>
              {generating ? `Generating ${totalExpected} builds...` : "Generate Builds"}
            </button>
          </div>
        </div>
      </div>

      {error && (
        <div style={{ marginBottom: "16px", padding: "10px 14px", background: "rgba(239,68,68,0.1)", borderRadius: "6px", border: "1px solid rgba(239,68,68,0.2)", color: "#ef4444", fontSize: "13px" }}>
          {error}
        </div>
      )}

      {generating && (
        <div style={{ textAlign: "center", padding: "40px", color: "#00eaff" }}>
          <div style={{ fontSize: "14px", marginBottom: "8px" }}>Generating builds using AI...</div>
          <div style={{ fontSize: "12px", color: "#666" }}>This may take a moment as we optimize each build per game.</div>
        </div>
      )}

      {results && results.length > 0 && (
        <div>
          <div style={{ display: "flex", gap: "8px", marginBottom: "16px", flexWrap: "wrap" }}>
            {results.map((r, i) => (
              <button key={r.game.id} onClick={() => setActiveGameTab(i)}
                style={{
                  padding: "10px 18px", borderRadius: "8px", cursor: "pointer",
                  background: activeGameTab === i ? "rgba(0,234,255,0.08)" : "#0d0d18",
                  border: `1px solid ${activeGameTab === i ? "rgba(0,234,255,0.4)" : "rgba(0,234,255,0.1)"}`,
                  color: activeGameTab === i ? "#00eaff" : "#e6e6e6",
                  fontFamily: "inherit", fontSize: "13px",
                  display: "flex", alignItems: "center", gap: "6px"
                }}
              >
                <span>{r.game.icon}</span>
                <span>{r.game.name}</span>
              </button>
            ))}
          </div>

          {results.map((r, gameIdx) => (
            activeGameTab === gameIdx && (
              <div key={r.game.id}>
                <div style={{ background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.15)", overflow: "hidden", marginBottom: "16px" }}>
                  <div style={{ padding: "16px 20px", borderBottom: "1px solid rgba(0,234,255,0.1)" }}>
                    <div style={{ fontSize: "16px", fontWeight: 700, color: "#e6e6e6", display: "flex", alignItems: "center", gap: "8px" }}>
                      <span>{r.game.icon}</span> {r.game.name}
                    </div>
                    {r.game.min && (
                      <div style={{ fontSize: "11px", color: "#666", marginTop: "4px", lineHeight: 1.5 }}>
                        Min: {r.game.min.cpu} • {r.game.min.gpu} • {r.game.min.ram} • {r.game.min.fps || ""}
                      </div>
                    )}
                  </div>

                  <div style={{ display: "flex", gap: "6px", padding: "12px 16px", flexWrap: "wrap", borderBottom: "1px solid rgba(0,234,255,0.05)" }}>
                    {r.tiers.map((t, tierIdx) => {
                      const price = totalPrice(t.build);
                      return (
                        <button key={t.tier.key} onClick={() => setActiveTierTabs(prev => ({ ...prev, [gameIdx]: tierIdx }))}
                          style={{
                            flex: 1, padding: "8px 12px", borderRadius: "6px", cursor: "pointer", textAlign: "center", minWidth: "100px",
                            background: activeTierTabs[gameIdx] === tierIdx ? "rgba(0,234,255,0.08)" : "transparent",
                            border: `1px solid ${activeTierTabs[gameIdx] === tierIdx ? "rgba(0,234,255,0.3)" : "rgba(255,255,255,0.06)"}`,
                            color: activeTierTabs[gameIdx] === tierIdx ? "#00eaff" : "#e6e6e6",
                            fontFamily: "inherit", fontSize: "inherit",
                            transition: "all 0.15s"
                          }}
                        >
                          <div style={{ fontSize: "11px", fontWeight: 700 }}>{t.tier.label}</div>
                          <div style={{ fontSize: "10px", color: activeTierTabs[gameIdx] === tierIdx ? "#00eaff" : "#666", marginTop: "1px" }}>
                            {t.build ? `£${price.toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}` : "Failed"}
                          </div>
                        </button>
                      );
                    })}
                  </div>

                  {r.tiers.map((t, tierIdx) => (
                    activeTierTabs[gameIdx] === tierIdx && (
                      <div key={t.tier.key}>
                        <div style={{ padding: "12px 16px", borderBottom: "1px solid rgba(0,234,255,0.05)" }}>
                          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                            <div>
                              <span style={{ color: "#ccc", fontWeight: 600, fontSize: "14px" }}>{t.tier.label}</span>
                              <span style={{ color: "#555", fontSize: "12px", marginLeft: "8px" }}>— {t.tier.desc}</span>
                            </div>
                            <span style={{ color: "#00eaff", fontWeight: 700, fontSize: "15px" }}>
                              {t.build ? `£${totalPrice(t.build).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}` : "—"}
                            </span>
                          </div>
                          <div style={{ fontSize: "11px", color: "#555", marginTop: "4px", display: "flex", gap: "16px", flexWrap: "wrap" }}>
                            <span>CPU: {r.game[t.tier.key === "above1" || t.tier.key === "above2" || t.tier.key === "above3" ? "above" : t.tier.key]?.cpu || "—"}</span>
                            <span>GPU: {r.game[t.tier.key === "above1" || t.tier.key === "above2" || t.tier.key === "above3" ? "above" : t.tier.key]?.gpu || "—"}</span>
                            <span>RAM: {r.game[t.tier.key === "above1" || t.tier.key === "above2" || t.tier.key === "above3" ? "above" : t.tier.key]?.ram || "—"}</span>
                          </div>
                        </div>
                        <BuildTable build={t.build} />
                        <div style={{ padding: "12px 16px", borderTop: "1px solid rgba(0,234,255,0.1)", textAlign: "center" }}>
                          <button className="button" onClick={() => handleApplyBuild(t.build)}
                            style={{ padding: "8px 24px", fontSize: "13px" }}>
                            Apply This Build
                          </button>
                        </div>
                      </div>
                    )
                  ))}
                </div>
              </div>
            )
          ))}
        </div>
      )}
    </div>
  );
}
