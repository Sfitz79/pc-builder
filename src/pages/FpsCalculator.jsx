import { useState, useEffect } from "react";
import { loadCSV } from "../utils/loadCSV";
import { getGamingPerformanceSummary } from "../utils/performance";
import { estimateRequiredWattage } from "../utils/compatibility";

const TOP_GAMES = [
  "Cyberpunk 2077", "Call of Duty: Warzone", "Fortnite", "Apex Legends", "Forza Horizon 5",
  "Black Myth: Wukong", "Elden Ring", "Grand Theft Auto VI", "Marvel Rivals", "Call of Duty: Black Ops 6",
  "Valorant", "Counter-Strike 2", "Minecraft", "Red Dead Redemption 2", "God of War",
  "The Last of Us", "Alan Wake 2", "Starfield", "Diablo IV", "Hogwarts Legacy",
];

const RESOLUTIONS = [
  { label: "1080p", multiplier: 1.0 },
  { label: "1440p", multiplier: 0.65 },
  { label: "4K", multiplier: 0.38 },
];

export default function FpsCalculator() {
  const [cpus, setCpus] = useState([]);
  const [gpus, setGpus] = useState([]);
  const [selectedCpu, setSelectedCpu] = useState("");
  const [selectedGpu, setSelectedGpu] = useState("");
  const [searchCpu, setSearchCpu] = useState("");
  const [searchGpu, setSearchGpu] = useState("");
  const [resolution, setResolution] = useState("1080p");
  const [results, setResults] = useState(null);

  useEffect(() => {
    loadCSV("cpu.csv").then(items => {
      const valid = items.filter(i => i.name && i.core_count);
      setCpus(valid);
    });
    loadCSV("gpu.csv").then(items => {
      const valid = items.filter(i => i.name && i.memory);
      setGpus(valid);
    });
  }, []);

  const filteredCpus = cpus.filter(c => (c.name || "").toLowerCase().includes(searchCpu.toLowerCase()));

  const gpuChipsets = [...new Set(gpus.map(g => g.chipset).filter(Boolean))].sort();
  const filteredGpuChipsets = gpuChipsets.filter(c => c.toLowerCase().includes(searchGpu.toLowerCase()));

  const handleCalculate = () => {
    if (!selectedCpu || !selectedGpu) return;
    const cpu = cpus.find(c => c.name === selectedCpu);
    const chipsetGpus = gpus.filter(g => g.chipset === selectedGpu);
    const gpu = chipsetGpus.sort((a, b) => parseFloat(a.price) - parseFloat(b.price))[0] || chipsetGpus[0];
    const selections = { cpu, gpu };
    const perf = getGamingPerformanceSummary(selections);
    const wattage = estimateRequiredWattage(cpu, gpu);

    const resMult = RESOLUTIONS.find(r => r.label === resolution)?.multiplier || 1;

    const gameFps = TOP_GAMES.map(game => {
      const gameMap = perf.gamePerformance?.find(g => g.name === game);
      const baseFps = gameMap?.boostedFps || 60;
      const fps = Math.round(baseFps * resMult);
      return { name: game, fps };
    });

    setResults({
      cpu: cpu.name,
      gpu: gpu.name,
      resolution,
      wattage,
      score: perf.score,
      grade: perf.gamingGrade,
      optimalResolution: perf.optimalResolution,
      gameFps,
    });
  };

  const getFpsColor = (fps) => {
    if (fps >= 144) return "#4ade80";
    if (fps >= 60) return "#00eaff";
    if (fps >= 30) return "#f59e0b";
    return "#ef4444";
  };

  const getFpsLabel = (fps) => {
    if (fps >= 144) return "Buttery Smooth";
    if (fps >= 60) return "Smooth";
    if (fps >= 30) return "Playable";
    return "Laggy";
  };

  return (
    <div style={{ maxWidth: "900px", margin: "0 auto" }}>
      <h1 style={{ fontSize: "24px", color: "#00eaff", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "6px" }}>
        FPS Calculator
      </h1>
      <p style={{ color: "#888", fontSize: "13px", marginBottom: "24px" }}>
        Estimate frames per second for your PC build across 20 popular games.
      </p>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "20px", marginBottom: "16px" }}>
        <div>
          <label style={{ fontSize: "12px", color: "#888", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "6px", display: "block" }}>
            CPU
          </label>
          <input type="text" placeholder="Search CPUs..." value={searchCpu}
            onChange={e => setSearchCpu(e.target.value)}
            style={{ width: "100%", marginBottom: "8px" }}
          />
          <select value={selectedCpu} onChange={e => setSelectedCpu(e.target.value)}
            style={{ width: "100%", height: "48px", cursor: "pointer" }}>
            <option value="">— Choose CPU —</option>
            {filteredCpus.map(c => (
              <option key={c.name} value={c.name}>{c.name}</option>
            ))}
          </select>
        </div>
        <div>
          <label style={{ fontSize: "12px", color: "#888", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "6px", display: "block" }}>
            GPU
          </label>
          <input type="text" placeholder="Search GPUs..." value={searchGpu}
            onChange={e => setSearchGpu(e.target.value)}
            style={{ width: "100%", marginBottom: "8px" }}
          />
          <select value={selectedGpu} onChange={e => setSelectedGpu(e.target.value)}
            style={{ width: "100%", height: "48px", cursor: "pointer" }}>
            <option value="">— Choose GPU Chipset —</option>
            {filteredGpuChipsets.map(c => (
              <option key={c} value={c}>{c}</option>
            ))}
          </select>
        </div>
      </div>

      <div style={{ marginBottom: "20px" }}>
        <label style={{ fontSize: "12px", color: "#888", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "8px", display: "block" }}>
          Resolution
        </label>
        <div style={{ display: "flex", gap: "8px" }}>
          {RESOLUTIONS.map(r => (
            <button key={r.label}
              onClick={() => setResolution(r.label)}
              className={`button ${resolution === r.label ? "" : "secondary"} small`}
            >
              {r.label}
            </button>
          ))}
        </div>
      </div>

      <button onClick={handleCalculate} disabled={!selectedCpu || !selectedGpu}
        className="button" style={{ width: "100%", marginBottom: "24px" }}>
        Calculate FPS
      </button>

      {results && (
        <>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: "12px", marginBottom: "20px" }}>
            <div style={{ background: "#0d0d18", borderRadius: "8px", padding: "14px", border: "1px solid rgba(0,234,255,0.1)", textAlign: "center" }}>
              <div style={{ fontSize: "10px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>Performance Score</div>
              <div style={{ fontSize: "24px", fontWeight: 800, color: "#00eaff" }}>{results.score}/100</div>
            </div>
            <div style={{ background: "#0d0d18", borderRadius: "8px", padding: "14px", border: "1px solid rgba(0,234,255,0.1)", textAlign: "center" }}>
              <div style={{ fontSize: "10px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>Tier</div>
              <div style={{ fontSize: "20px", fontWeight: 800, color: "#4ade80" }}>{results.grade}</div>
            </div>
            <div style={{ background: "#0d0d18", borderRadius: "8px", padding: "14px", border: "1px solid rgba(0,234,255,0.1)", textAlign: "center" }}>
              <div style={{ fontSize: "10px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>Estimated Wattage</div>
              <div style={{ fontSize: "24px", fontWeight: 800, color: "#ff005e" }}>{results.wattage}W</div>
            </div>
          </div>

          <div style={{ background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.15)", padding: "20px" }}>
            <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "14px" }}>
              Expected FPS at {results.resolution}
            </div>
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(250px, 1fr))", gap: "6px" }}>
              {results.gameFps.map(game => (
                <div key={game.name} style={{
                  display: "flex", justifyContent: "space-between", alignItems: "center",
                  padding: "10px 14px", borderRadius: "6px",
                  background: "rgba(255,255,255,0.02)", border: "1px solid rgba(255,255,255,0.04)"
                }}>
                  <span style={{ fontSize: "13px", color: "#ccc", fontWeight: 500 }}>{game.name}</span>
                  <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
                    <span style={{ fontSize: "16px", fontWeight: 800, color: getFpsColor(game.fps) }}>{game.fps}</span>
                    <span style={{ fontSize: "10px", color: "#666" }}>FPS</span>
                    <span style={{
                      fontSize: "9px", padding: "2px 6px", borderRadius: "3px",
                      background: `${getFpsColor(game.fps)}15`,
                      color: getFpsColor(game.fps), fontWeight: 600
                    }}>
                      {getFpsLabel(game.fps)}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </>
      )}

      {!results && (selectedCpu || selectedGpu) && (
        <div style={{ textAlign: "center", padding: "40px", color: "#555", fontSize: "13px" }}>
          Select both a CPU and GPU, then click "Calculate FPS".
        </div>
      )}
    </div>
  );
}
