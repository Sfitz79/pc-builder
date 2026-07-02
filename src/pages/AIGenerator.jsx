import { useState } from "react";
import { usePCStore } from "../store/usePCStore";
import { generateBuild } from "../utils/aiBuildGenerator";
import { BUILDER_CATEGORIES, SUBCATEGORY_GROUPS } from "../utils/builderConfig";

const USE_CASES = [
  { id: "gaming", label: "Gaming", icon: "🎮", desc: "High FPS for the latest games" },
  { id: "streaming", label: "Gaming + Streaming", icon: "🎥", desc: "Play and stream simultaneously" },
  { id: "workstation", label: "Workstation", icon: "💼", desc: "AI / ML, video editing, 3D rendering, CAD" },
  { id: "content-creation", label: "Content Creation", icon: "🎬", desc: "Professional video editing & production" },
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
        Tell us your budget and what you'll use your PC for — our AI will recommend the perfect components.
      </p>

      {step === 1 && (
        <div>
          <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "12px" }}>
            What will you use your PC for?
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: "12px", marginBottom: "24px" }}>
            {USE_CASES.map(uc => (
              <button key={uc.id} onClick={() => { setUseCase(uc.id); setStep(2); }}
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
