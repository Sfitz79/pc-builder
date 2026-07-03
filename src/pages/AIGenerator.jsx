import { useState, useMemo } from "react";
import { usePCStore } from "../store/usePCStore";
import { generateBuild } from "../utils/aiBuildGenerator";
import { GAME_REQUIREMENTS } from "../utils/partsKnowledgeBase";
import { BUILDER_CATEGORIES, SUBCATEGORY_GROUPS } from "../utils/builderConfig";

const USE_CASES = [
  { id: "gaming", label: "Gaming", icon: "🎮", desc: "High FPS for the latest games" },
  { id: "streaming", label: "Gaming + Streaming", icon: "🎥", desc: "Play and stream simultaneously" },
  { id: "workstation", label: "Workstation", icon: "💼", desc: "AI / ML, video editing, 3D rendering, CAD" },
  { id: "content-creation", label: "Content Creation", icon: "🎬", desc: "Professional video editing & production" },
  { id: "game-based", label: "Based on Games", icon: "🎯", desc: "Generate builds for specific games at multiple tiers" },
];

const GAME_LIST = Object.entries(GAME_REQUIREMENTS).map(([id, game]) => ({
  id, name: game.name, icon: game.icon || "🎮"
}));

const GAME_TIERS = [
  { key: "min", label: "Minimum Spec", budget: 600 },
  { key: "rec", label: "Recommended", budget: 1200 },
  { key: "above1", label: "Well Above #1", budget: 2000 },
  { key: "above2", label: "Well Above #2", budget: 3500 },
  { key: "above3", label: "Well Above #3", budget: 5500 },
];

const RESOLUTION_OPTIONS = [
  { value: "1080p", label: "1080p", desc: "Great for budget builds & competitive gaming", icon: "🖥️" },
  { value: "1440p", label: "1440p", desc: "Sweet spot for immersive gaming & content creation", icon: "🖥️" },
  { value: "4k", label: "4K", desc: "Ultra-high detail for premium experiences", icon: "🖥️" },
];

const WORKSTATION_TIERS = [
  { id: "ws-1080p-basic", title: "1080p — Basic", desc: "Entry ML, light fine-tuning, basic 1080p editing", specs: "Ultra 7 265K • 64GB DDR5 • RTX 4060 Ti 16GB • 2TB NVMe", resolution: "1080p", budget: 1500 },
  { id: "ws-1080p-mid", title: "1080p — Mid", desc: "Mid ML scripts, multi-app workflows, fast 1080p editing", specs: "Ultra 7 265K • 64GB DDR5 • RTX 5060 Ti 16GB • 2TB NVMe", resolution: "1080p", budget: 1750 },
  { id: "ws-1080p-high", title: "1080p — High", desc: "Advanced AI inference, complex 1080p editing with effects", specs: "Ultra 9 285K • 96GB DDR5 • RTX 5070 Ti 16GB • 4TB NVMe", resolution: "1080p", budget: 2400 },
  { id: "ws-1440p-basic", title: "1440p — Basic", desc: "Basic AI workloads, data science, 1440p editing", specs: "TR 9960X • 64GB ECC • RTX 5070 12GB • 2TB NVMe", resolution: "1440p", budget: 3300 },
  { id: "ws-1440p-mid", title: "1440p — Mid", desc: "Deep learning, AI image gen, standard 1440p editing", specs: "TR 9960X • 96GB ECC • RTX 5070 Ti 16GB • 4TB Gen5", resolution: "1440p", budget: 4000 },
  { id: "ws-1440p-high", title: "1440p — High", desc: "Medium LLMs, AI agent scripting, high-fidelity 1440p", specs: "TR 9960X • 128GB ECC • RTX 5080 16GB • 4TB Gen5", resolution: "1440p", budget: 4550 },
  { id: "ws-4k-basic", title: "4K — Basic", desc: "LLM fine-tuning, neural rendering, basic 4K editing", specs: "Ultra 9 285K • 128GB DDR5 • RTX 5090 32GB • 8TB Gen5", resolution: "4k", budget: 4400 },
  { id: "ws-4k-mid", title: "4K — Mid", desc: "Serious deep learning, multi-agent, uncompromised 4K/8K", specs: "TR PRO 9975WX • 192GB ECC • RTX 6000 Ada 48GB • 8TB Gen5", resolution: "4k", budget: 12500 },
  { id: "ws-4k-high", title: "4K — High", desc: "Heavy LLM training (70B+), extreme research, 8K multi-stream", specs: "TR PRO 9995WX • 256GB ECC • RTX PRO 6000 96GB • 16TB Gen5", resolution: "4k", budget: 24000 },
];

function getBudgetRanges(resolution) {
  if (resolution === "1440p" || resolution === "4k") {
    return [
      { min: 1350, max: 1900, label: "£1,350-1,900 (1440p Basic)" },
      { min: 1900, max: 2500, label: "£1,900-2,500 (1440p Performance)" },
      { min: 2500, max: 3200, label: "£2,500-3,200 (1440p Ultra)" },
      { min: 3200, max: 5500, label: "£3,200-5,500 (1440p Professional)" },
    ];
  }
  return [
    { min: 700, max: 1000, label: "£700-1,000 (1080p Basic)" },
    { min: 1000, max: 1500, label: "£1,000-1,500 (1080p Entry)" },
    { min: 1500, max: 2000, label: "£1,500-2,000 (1080p Performance)" },
    { min: 2000, max: 3000, label: "£2,000-3,000 (1080p Ultra)" },
    { min: 3000, max: 4500, label: "£3,000-4,500 (1080p Professional)" },
  ];
}

function ToggleRow({ label, value, onChange, children }) {
  return (
    <div style={{ display: "flex", alignItems: "center", gap: "10px", padding: "10px 14px", background: "#0d0d18", borderRadius: "8px", border: "1px solid rgba(0,234,255,0.1)" }}>
      <button onClick={() => onChange(!value)}
        style={{
          width: "36px", height: "20px", borderRadius: "10px", cursor: "pointer", border: "none", position: "relative", transition: "background 0.2s", flexShrink: 0,
          background: value ? "rgba(0,234,255,0.5)" : "#333",
        }}
        aria-label={`Toggle ${label}`}
      >
        <span style={{
          position: "absolute", top: "2px", width: "16px", height: "16px", borderRadius: "50%", background: "#fff", transition: "left 0.2s",
          left: value ? "18px" : "2px",
        }} />
      </button>
      <span style={{ fontSize: "13px", color: "#ccc", fontWeight: 600 }}>{label}</span>
      {children}
    </div>
  );
}

export default function AIGenerator() {
  const [step, setStep] = useState(1);
  const [useCase, setUseCase] = useState("");
  const [budget, setBudget] = useState(1200);
  const [customBudget, setCustomBudget] = useState(false);
  const [color, setColor] = useState("any");
  const [loading, setLoading] = useState(false);
  const [builds, setBuilds] = useState(null);
  const [selectedBuild, setSelectedBuild] = useState(1);
  const [error, setError] = useState("");
  const [needMonitor, setNeedMonitor] = useState(true);
  const [monitorResolution, setMonitorResolution] = useState("auto");
  const [needMouse, setNeedMouse] = useState(true);
  const [needKeyboard, setNeedKeyboard] = useState(true);
  const [needSpeakers, setNeedSpeakers] = useState(false);
  const [needWifi, setNeedWifi] = useState(false);
  const [gameSearch, setGameSearch] = useState("");
  const [selectedGameIds, setSelectedGameIds] = useState([]);
  const [gameResults, setGameResults] = useState(null);
  const [activeGameTab, setActiveGameTab] = useState(0);
  const [activeGameTier, setActiveGameTier] = useState(0);
  const adminMode = usePCStore(s => s.adminMode);
  const setComponent = usePCStore(s => s.setComponent);

  const handleGenerate = async () => {
    setLoading(true);
    setError("");
    try {
      const options = { needMonitor, monitorResolution, needMouse, needKeyboard, needSpeakers, needWifi };
      const variants = [
        { label: "Budget-Friendly", desc: "Easily within budget", multiplier: 0.85 },
        { label: "Recommended", desc: "Best price-to-performance at your budget", multiplier: 1.0 },
        { label: "Premium", desc: "Above budget — top-tier performance", multiplier: 1.3 },
      ];
      const results = await Promise.all(
        variants.map(v => generateBuild(Math.round(budget * v.multiplier), useCase, color, options))
      );
      const resultBuilds = results.map((build, i) => ({ ...variants[i], build }));
      if (resultBuilds.every(b => Object.keys(b.build).length === 0)) {
        setError("Could not generate builds with the given budget. Try increasing your budget.");
        setLoading(false);
        return;
      }
      setBuilds(resultBuilds);
      setSelectedBuild(1);
      setStep(5);
    } catch {
      setError("Failed to generate builds. Please try again.");
    }
    setLoading(false);
  };

  const filteredGames = useMemo(() => {
    if (!gameSearch.trim()) return GAME_LIST;
    const q = gameSearch.toLowerCase();
    return GAME_LIST.filter(g => g.name.toLowerCase().includes(q));
  }, [gameSearch]);

  const handleGameGenerate = async () => {
    if (selectedGameIds.length === 0) return;
    setLoading(true);
    setError("");
    setGameResults(null);
    const selectedGames = selectedGameIds.map(id => ({ id, ...GAME_REQUIREMENTS[id] }));
    const results = [];
    for (const game of selectedGames) {
      const tiers = [];
      for (const tier of GAME_TIERS) {
        try {
          const build = await generateBuild(tier.budget, "gaming", "any", {
            needMonitor: false, needMouse: true, needKeyboard: true,
            needSpeakers: false, needWifi: false
          });
          tiers.push({ tier, build });
        } catch {
          tiers.push({ tier, build: null });
        }
      }
      results.push({ game, tiers });
    }
    setGameResults(results);
    setActiveGameTab(0);
    setActiveGameTier(0);
    setLoading(false);
    setStep("game-results");
  };

  const handleApplyBuild = (build) => {
    if (!build) return;
    usePCStore.getState().resetBuild();
    for (const [cat, item] of Object.entries(build)) {
      setComponent(cat, item);
    }
  };

  const totalPrice = (build) => {
    if (!build) return 0;
    return Object.values(build).reduce((sum, item) => sum + (parseFloat(item.price) || 0), 0);
  };

  function BuildTable({ build }) {
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
        </tbody>
      </table>
    );
  }

  return (
    <div style={{ maxWidth: "900px", margin: "0 auto" }}>
      <h1 style={{ fontSize: "24px", color: "#00eaff", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "6px" }}>
        AI Build Generator
      </h1>
      <p style={{ color: "#888", fontSize: "13px", marginBottom: "24px" }}>
        Tell us your budget and what you'll use your PC for — our AI will recommend the perfect components. Or pick specific games and get tiered builds from minimum to no-compromise.
      </p>

      {step === 1 && (
        <div>
          <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "12px" }}>
            What will you use your PC for?
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: "12px", marginBottom: "24px" }}>
            {USE_CASES.map(uc => (
              <button key={uc.id} onClick={() => {
                setUseCase(uc.id);
                if (uc.id === "game-based") setStep("game-select");
                else setStep(2);
              }}
                style={{
                  padding: "20px", borderRadius: "10px", cursor: "pointer", textAlign: "left",
                  background: useCase === uc.id ? "rgba(0,234,255,0.08)" : "#0d0d18",
                  border: `1px solid ${useCase === uc.id ? "rgba(0,234,255,0.3)" : "rgba(0,234,255,0.1)"}`,
                  color: "#e6e6e6", transition: "all 0.2s", fontFamily: "inherit", fontSize: "inherit"
                }}
                onMouseEnter={e => e.currentTarget.style.borderColor = "rgba(0,234,255,0.3)"}
                onMouseLeave={e => e.currentTarget.style.borderColor = useCase === uc.id ? "rgba(0,234,255,0.3)" : "rgba(0,234,255,0.1)"}
              >
                <div style={{ fontSize: "28px", marginBottom: "8px" }}>{uc.icon}</div>
                <div style={{ fontSize: "15px", fontWeight: 700, marginBottom: "4px" }}>{uc.label}</div>
                <div style={{ fontSize: "12px", color: "#666" }}>{uc.desc}</div>
              </button>
            ))}
          </div>
        </div>
      )}

      {step === "game-select" && (
        <div>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "16px" }}>
            <div>
              <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>
                Select Games
              </div>
              <div style={{ fontSize: "13px", color: "#888", marginTop: "2px" }}>
                Choose up to 5 games — we'll generate 5 builds per game at different tiers
              </div>
            </div>
            <button className="button secondary small" onClick={() => { setStep(1); setUseCase(""); }}>
              ← Change Mode
            </button>
          </div>

          <div style={{ background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.15)", padding: "20px", marginBottom: "24px" }}>
            <div style={{ marginBottom: "12px" }}>
              <input
                type="text" placeholder="Search games..."
                value={gameSearch} onChange={e => setGameSearch(e.target.value)}
                style={{
                  width: "100%", padding: "10px 14px", borderRadius: "8px", border: "1px solid rgba(255,255,255,0.1)",
                  background: "#0a0a0f", color: "#e6e6e6", fontFamily: "inherit", fontSize: "14px",
                  outline: "none", boxSizing: "border-box"
                }}
              />
            </div>

            <div style={{ maxHeight: "300px", overflowY: "auto", marginBottom: "12px" }}>
              {filteredGames.map(game => {
                const checked = selectedGameIds.includes(game.id);
                const disabled = !checked && selectedGameIds.length >= 5;
                return (
                  <label key={game.id} onClick={() => { if (!disabled) setSelectedGameIds(prev => prev.includes(game.id) ? prev.filter(id => id !== game.id) : prev.length >= 5 ? prev : [...prev, game.id]); }}
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
                    <span style={{ fontSize: "13px", color: "#ccc" }}>{game.name}</span>
                  </label>
                );
              })}
              {filteredGames.length === 0 && (
                <div style={{ textAlign: "center", padding: "20px", color: "#555", fontSize: "13px" }}>
                  No games found matching "{gameSearch}"
                </div>
              )}
            </div>

            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
              <span style={{ fontSize: "12px", color: "#888" }}>
                {selectedGameIds.length}/5 games selected
                {selectedGameIds.length > 0 && <span> • {selectedGameIds.length * 5} builds to generate</span>}
              </span>
              <div style={{ display: "flex", gap: "8px" }}>
                {selectedGameIds.length > 0 && (
                  <button className="button secondary small" onClick={() => setSelectedGameIds([])}>Clear All</button>
                )}
                <button onClick={handleGameGenerate} disabled={selectedGameIds.length === 0 || loading}
                  className="button" style={{ padding: "10px 24px", fontSize: "14px" }}>
                  {loading ? "Generating..." : "Generate Builds"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {step === 2 && useCase === "workstation" && (
        <div>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "16px" }}>
            <div>
              <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>
                Choose Your Workstation Tier
              </div>
              <div style={{ fontSize: "13px", color: "#888", marginTop: "2px" }}>
                Each tier pre-selects the ideal budget and target resolution
              </div>
            </div>
            <button className="button secondary small" onClick={() => { setStep(1); setUseCase(""); }}>
              ← Change Use Case
            </button>
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(260px, 1fr))", gap: "12px", marginBottom: "20px" }}>
            {WORKSTATION_TIERS.map(tier => (
              <button key={tier.id} onClick={() => {
                setMonitorResolution(tier.resolution);
                setNeedMonitor(true);
                setBudget(tier.budget);
                setCustomBudget(false);
                setStep(3);
              }}
                style={{
                  padding: "14px", borderRadius: "10px", cursor: "pointer", textAlign: "left",
                  background: "#0d0d18",
                  border: "1px solid rgba(0,234,255,0.1)", color: "#e6e6e6",
                  transition: "all 0.2s", fontFamily: "inherit", fontSize: "inherit",
                  display: "flex", flexDirection: "column", gap: "4px",
                }}
                onMouseEnter={e => e.currentTarget.style.borderColor = "rgba(0,234,255,0.3)"}
                onMouseLeave={e => e.currentTarget.style.borderColor = "rgba(0,234,255,0.1)"}
              >
                <div style={{ fontSize: "14px", fontWeight: 700 }}>{tier.title}</div>
                <div style={{ fontSize: "11px", color: "#00eaff" }}>{tier.desc}</div>
                <div style={{ fontSize: "11px", color: "#666", lineHeight: 1.4, marginTop: "2px" }}>{tier.specs}</div>
                <div style={{ fontSize: "14px", color: "#00eaff", fontWeight: 700, marginTop: "4px" }}>
                  £{tier.budget.toLocaleString('en-GB')}
                </div>
              </button>
            ))}
          </div>
        </div>
      )}

      {step === 2 && useCase !== "workstation" && (
        <div>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "16px" }}>
            <div>
              <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>
                Target Resolution
              </div>
              <div style={{ fontSize: "13px", color: "#888", marginTop: "2px" }}>
                {USE_CASES.find(uc => uc.id === useCase)?.label}
              </div>
            </div>
            <button className="button secondary small" onClick={() => { setStep(1); setUseCase(""); }}>
              ← Change Use Case
            </button>
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: "12px", marginBottom: "24px" }}>
            {RESOLUTION_OPTIONS.map(res => (
              <button key={res.value} onClick={() => { setMonitorResolution(res.value); setNeedMonitor(true); setStep(3); }}
                style={{
                  padding: "24px", borderRadius: "10px", cursor: "pointer", textAlign: "center",
                  background: "#0d0d18",
                  border: "1px solid rgba(0,234,255,0.1)", color: "#e6e6e6",
                  transition: "all 0.2s", fontFamily: "inherit", fontSize: "inherit"
                }}
                onMouseEnter={e => e.currentTarget.style.borderColor = "rgba(0,234,255,0.3)"}
                onMouseLeave={e => e.currentTarget.style.borderColor = "rgba(0,234,255,0.1)"}
              >
                <div style={{ fontSize: "32px", marginBottom: "8px" }}>{res.icon}</div>
                <div style={{ fontSize: "18px", fontWeight: 700, marginBottom: "4px" }}>{res.label}</div>
                <div style={{ fontSize: "12px", color: "#666" }}>{res.desc}</div>
              </button>
            ))}
          </div>
        </div>
      )}

      {step === 3 && (
        <div>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "16px" }}>
            <div>
              <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>
                {monitorResolution !== "auto"
                  ? `${monitorResolution.toUpperCase()} Budget`
                  : "Target Budget"}
              </div>
              <div style={{ fontSize: "32px", fontWeight: 800, color: "#00eaff", marginTop: "4px" }}>
                £{budget.toLocaleString('en-GB')}
              </div>
            </div>
            <button className="button secondary small" onClick={() => setStep(2)}>
              ← {useCase === "workstation" ? "Change Tier" : "Change Resolution"}
            </button>
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(160px, 1fr))", gap: "8px", marginBottom: "16px" }}>
            {getBudgetRanges(monitorResolution).map(r => (
              <button key={r.label} onClick={() => { setBudget(r.min); setCustomBudget(false); }}
                style={{
                  padding: "12px", borderRadius: "8px", cursor: "pointer",
                  background: budget >= r.min && budget <= r.max && !customBudget ? "rgba(0,234,255,0.08)" : "#0d0d18",
                  border: `1px solid ${budget >= r.min && budget <= r.max && !customBudget ? "rgba(0,234,255,0.3)" : "rgba(0,234,255,0.1)"}`,
                  color: "#e6e6e6", fontFamily: "inherit", fontSize: "inherit", textAlign: "center"
                }}
              >
                <div style={{ fontSize: "13px", fontWeight: 600 }}>{r.label}</div>
              </button>
            ))}
          </div>

          <div style={{ marginBottom: "20px" }}>
            <label style={{ fontSize: "12px", color: "#888", display: "block", marginBottom: "6px" }}>
              Custom Budget: £{budget}
            </label>
            <input type="range" min="300" max="30000" step="100" value={budget}
              onChange={e => { setBudget(Number(e.target.value)); setCustomBudget(true); }}
              style={{ width: "100%", accentColor: "#00eaff" }}
            />
            <div style={{ display: "flex", justifyContent: "space-between", fontSize: "11px", color: "#555" }}>
              <span>£300</span>
              <span>£30,000</span>
            </div>
          </div>

          <div style={{ marginBottom: "16px", display: "flex", alignItems: "center", gap: "12px" }}>
            <label style={{ fontSize: "12px", color: "#888", whiteSpace: "nowrap" }}>
              Case Color Preference:
            </label>
            <div style={{ display: "flex", gap: "6px" }}>
              {[
                { value: "any", label: "Any", color: "#888" },
                { value: "Black", label: "Black", color: "#222" },
                { value: "White", label: "White", color: "#ddd" },
              ].map(c => (
                <button key={c.value} onClick={() => setColor(c.value)}
                  style={{
                    padding: "6px 14px", borderRadius: "6px", cursor: "pointer",
                    background: color === c.value ? "rgba(0,234,255,0.08)" : "#0d0d18",
                    border: `1px solid ${color === c.value ? "rgba(0,234,255,0.3)" : "rgba(0,234,255,0.1)"}`,
                    color: color === c.value ? "#00eaff" : "#888",
                    fontFamily: "inherit", fontSize: "13px",
                    display: "flex", alignItems: "center", gap: "6px"
                  }}
                >
                  <span style={{ display: "inline-block", width: "10px", height: "10px", borderRadius: "50%",
                    background: c.value === "any" ? "linear-gradient(90deg,#222 50%,#ddd 50%)" : c.color,
                    border: c.value === "White" ? "1px solid #555" : "none"
                  }}></span>
                  {c.label}
                </button>
              ))}
            </div>
          </div>

          <button onClick={() => setStep(4)} className="button" style={{ width: "100%", padding: "14px", fontSize: "15px" }}>
            Continue → Extras
          </button>
        </div>
      )}

      {step === 4 && (
        <div>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "16px" }}>
            <div>
              <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>
                Peripherals & Extras
              </div>
              <div style={{ fontSize: "13px", color: "#888", marginTop: "2px" }}>
                {USE_CASES.find(uc => uc.id === useCase)?.label} • {monitorResolution.toUpperCase()} • £{budget.toLocaleString('en-GB')} budget
              </div>
            </div>
            <button className="button secondary small" onClick={() => setStep(3)}>
              ← Back to Budget
            </button>
          </div>

          <div style={{ display: "flex", flexDirection: "column", gap: "12px", marginBottom: "20px" }}>
            <ToggleRow label="Monitor" value={needMonitor} onChange={setNeedMonitor} />
            <ToggleRow label="Mouse" value={needMouse} onChange={setNeedMouse} />
            <ToggleRow label="Keyboard" value={needKeyboard} onChange={setNeedKeyboard} />
            <ToggleRow label="Speakers" value={needSpeakers} onChange={setNeedSpeakers} />
            <ToggleRow label="WiFi Card" value={needWifi} onChange={setNeedWifi} />
          </div>

          <button onClick={handleGenerate} disabled={loading}
            className="button" style={{ width: "100%", padding: "14px", fontSize: "15px" }}>
            {loading ? "Generating Builds..." : "Generate My Build"}
          </button>

          {error && (
            <div style={{ marginTop: "12px", padding: "10px 14px", background: "rgba(239,68,68,0.1)", borderRadius: "6px", border: "1px solid rgba(239,68,68,0.2)", color: "#ef4444", fontSize: "13px" }}>
              {error}
            </div>
          )}
        </div>
      )}

      {step === "game-results" && gameResults && (
        <div>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "16px", flexWrap: "wrap", gap: "10px" }}>
            <div>
              <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>
                Game-Based Builds
              </div>
              <div style={{ fontSize: "13px", color: "#888", marginTop: "2px" }}>
                {gameResults.length} game{gameResults.length > 1 ? "s" : ""} • {gameResults.reduce((s, r) => s + r.tiers.length, 0)} builds total
              </div>
            </div>
            <button className="button secondary small" onClick={() => { setStep("game-select"); setGameResults(null); }}>
              ← Change Games
            </button>
          </div>

          <div style={{ display: "flex", gap: "8px", marginBottom: "16px", flexWrap: "wrap" }}>
            {gameResults.map((r, i) => (
              <button key={r.game.id} onClick={() => { setActiveGameTab(i); setActiveGameTier(0); }}
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

          {gameResults.map((r, gameIdx) => activeGameTab === gameIdx && (
            <div key={r.game.id} style={{ background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.15)", overflow: "hidden" }}>
              <div style={{ padding: "16px 20px", borderBottom: "1px solid rgba(0,234,255,0.1)" }}>
                <div style={{ fontSize: "16px", fontWeight: 700, color: "#e6e6e6", display: "flex", alignItems: "center", gap: "8px" }}>
                  <span>{r.game.icon}</span> {r.game.name}
                </div>
              </div>

              <div style={{ display: "flex", gap: "6px", padding: "12px 16px", flexWrap: "wrap", borderBottom: "1px solid rgba(0,234,255,0.05)" }}>
                {r.tiers.map((t, i) => {
                  const price = t.build ? Object.values(t.build).reduce((s, item) => s + (parseFloat(item.price) || 0), 0) : 0;
                  return (
                    <button key={t.tier.key} onClick={() => setActiveGameTier(i)}
                      style={{
                        flex: 1, padding: "8px 12px", borderRadius: "6px", cursor: "pointer", textAlign: "center", minWidth: "100px",
                        background: activeGameTier === i ? "rgba(0,234,255,0.08)" : "transparent",
                        border: `1px solid ${activeGameTier === i ? "rgba(0,234,255,0.3)" : "rgba(255,255,255,0.06)"}`,
                        color: activeGameTier === i ? "#00eaff" : "#e6e6e6", fontFamily: "inherit", fontSize: "inherit"
                      }}
                    >
                      <div style={{ fontSize: "11px", fontWeight: 700 }}>{t.tier.label}</div>
                      <div style={{ fontSize: "10px", color: activeGameTier === i ? "#00eaff" : "#666", marginTop: "1px" }}>
                        {t.build ? `£${price.toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}` : "Failed"}
                      </div>
                    </button>
                  );
                })}
              </div>

              {r.tiers.map((t, i) => activeGameTier === i && (
                <div key={t.tier.key}>
                  <div style={{ padding: "12px 16px", borderBottom: "1px solid rgba(0,234,255,0.05)" }}>
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                      <span style={{ color: "#ccc", fontWeight: 600, fontSize: "14px" }}>{t.tier.label}</span>
                      <span style={{ color: "#00eaff", fontWeight: 700, fontSize: "15px" }}>
                        {t.build ? `£${Object.values(t.build).reduce((s, item) => s + (parseFloat(item.price) || 0), 0).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}` : "—"}
                      </span>
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
              ))}
            </div>
          ))}
        </div>
      )}

      {step === 5 && builds && (
        <div>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "16px", flexWrap: "wrap", gap: "10px" }}>
            <div>
              <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>
                Your AI-Generated Builds
              </div>
              <div style={{ fontSize: "13px", color: "#888", marginTop: "2px" }}>
                {USE_CASES.find(uc => uc.id === useCase)?.label} • {monitorResolution.toUpperCase()} • £{budget.toLocaleString('en-GB')}
                {color !== "any" && <span> • {color} case</span>}
              </div>
            </div>
            <button className="button secondary small" onClick={() => { setStep(4); setBuilds(null); }}>
              ← Change Options
            </button>
          </div>

          <div style={{ display: "flex", gap: "8px", marginBottom: "16px", flexWrap: "wrap" }}>
            {builds.map((v, i) => (
              <button key={v.label} onClick={() => setSelectedBuild(i)}
                style={{
                  flex: 1, padding: "10px 16px", borderRadius: "8px", cursor: "pointer", textAlign: "center", minWidth: "140px",
                  background: selectedBuild === i ? "rgba(0,234,255,0.08)" : "#0d0d18",
                  border: `1px solid ${selectedBuild === i ? "rgba(0,234,255,0.4)" : "rgba(0,234,255,0.1)"}`,
                  color: selectedBuild === i ? "#00eaff" : "#e6e6e6",
                  fontFamily: "inherit", fontSize: "inherit",
                }}
              >
                <div style={{ fontSize: "13px", fontWeight: 700 }}>{v.label}</div>
                <div style={{ fontSize: "11px", color: selectedBuild === i ? "#00eaff" : "#666", marginTop: "2px" }}>
                  £{totalPrice(v.build).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
                </div>
                {i === 1 && <div style={{ fontSize: "10px", color: "#00eaff", marginTop: "2px" }}>★ Recommended</div>}
              </button>
            ))}
          </div>

          <div style={{ background: "#0d0d18", borderRadius: "12px", border: selectedBuild === 1 ? "1px solid rgba(0,234,255,0.3)" : "1px solid rgba(0,234,255,0.15)", overflow: "hidden", transition: "border 0.2s" }}>
            <div style={{ padding: "12px 16px", borderBottom: "1px solid rgba(0,234,255,0.1)", display: "flex", justifyContent: "space-between", alignItems: "center" }}>
              <span style={{ color: "#ccc", fontWeight: 600, fontSize: "14px" }}>
                {builds[selectedBuild].label} — {builds[selectedBuild].desc}
              </span>
              <span style={{ color: "#00eaff", fontWeight: 700, fontSize: "15px" }}>
                £{totalPrice(builds[selectedBuild].build).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}
              </span>
            </div>
            <BuildTable build={builds[selectedBuild].build} />
            <div style={{ padding: "12px 16px", borderTop: "1px solid rgba(0,234,255,0.1)", textAlign: "center" }}>
              <button className="button" onClick={() => handleApplyBuild(builds[selectedBuild].build)}
                style={{ padding: "8px 24px", fontSize: "13px" }}>
                Apply This Build
              </button>
            </div>
          </div>

          <div style={{ marginTop: "12px", display: "flex", gap: "8px", justifyContent: "center" }}>
            {builds.map((v, i) => (
              i !== selectedBuild && Object.keys(v.build).length > 0 && (
                <button key={v.label} className="button secondary small" onClick={() => handleApplyBuild(v.build)}>
                  Apply {v.label}
                </button>
              )
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
