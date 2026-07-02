import { useState, useEffect } from "react";
import { loadCSV } from "../utils/loadCSV";
import { calculateBottleneck } from "../utils/bottleneck";

export default function BottleneckCalculator() {
  const [cpus, setCpus] = useState([]);
  const [gpus, setGpus] = useState([]);
  const [selectedCpu, setSelectedCpu] = useState("");
  const [selectedGpu, setSelectedGpu] = useState("");
  const [result, setResult] = useState(null);
  const [searchCpu, setSearchCpu] = useState("");
  const [searchGpu, setSearchGpu] = useState("");

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
  const filteredGpus = gpus.filter(g => (g.name || "").toLowerCase().includes(searchGpu.toLowerCase()));

  const handleCalculate = () => {
    if (!selectedCpu || !selectedGpu) return;
    const cpu = cpus.find(c => c.name === selectedCpu);
    const gpu = gpus.find(g => g.name === selectedGpu);
    const res = calculateBottleneck(cpu, gpu);
    setResult(res);
  };

  return (
    <div style={{ maxWidth: "900px", margin: "0 auto" }}>
      <h1 style={{ fontSize: "24px", color: "#00eaff", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "6px" }}>
        Bottleneck Calculator
      </h1>
      <p style={{ color: "#888", fontSize: "13px", marginBottom: "24px" }}>
        Check CPU and GPU compatibility — find out which component is holding back your gaming performance.
      </p>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "20px", marginBottom: "24px" }}>
        <div>
          <label style={{ fontSize: "12px", color: "#888", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "6px", display: "block" }}>
            Select CPU
          </label>
          <input
            type="text" placeholder="Search CPUs..." value={searchCpu}
            onChange={e => setSearchCpu(e.target.value)}
            style={{ width: "100%", marginBottom: "8px" }}
          />
          <select
            value={selectedCpu} onChange={e => setSelectedCpu(e.target.value)}
            style={{ width: "100%", height: "48px", cursor: "pointer" }}
          >
            <option value="">— Choose CPU —</option>
            {filteredCpus.map(c => (
              <option key={c.name} value={c.name}>{c.name}</option>
            ))}
          </select>
          {filteredCpus.length === 0 && searchCpu && (
            <div style={{ color: "#f59e0b", fontSize: "12px", marginTop: "4px" }}>No CPUs found</div>
          )}
        </div>
        <div>
          <label style={{ fontSize: "12px", color: "#888", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "6px", display: "block" }}>
            Select GPU
          </label>
          <input
            type="text" placeholder="Search GPUs..." value={searchGpu}
            onChange={e => setSearchGpu(e.target.value)}
            style={{ width: "100%", marginBottom: "8px" }}
          />
          <select
            value={selectedGpu} onChange={e => setSelectedGpu(e.target.value)}
            style={{ width: "100%", height: "48px", cursor: "pointer" }}
          >
            <option value="">— Choose GPU —</option>
            {filteredGpus.map(g => (
              <option key={g.name} value={g.name}>{g.name}</option>
            ))}
          </select>
          {filteredGpus.length === 0 && searchGpu && (
            <div style={{ color: "#f59e0b", fontSize: "12px", marginTop: "4px" }}>No GPUs found</div>
          )}
        </div>
      </div>

      <button
        onClick={handleCalculate} disabled={!selectedCpu || !selectedGpu}
        className="button" style={{ width: "100%", marginBottom: "24px" }}
      >
        Calculate Bottleneck
      </button>

      {result && (
        <div style={{ background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.15)", padding: "24px" }}>
          <div style={{ fontSize: "12px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "16px" }}>
            Results
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "16px", marginBottom: "20px" }}>
            <div style={{ padding: "16px", background: "rgba(0,234,255,0.04)", borderRadius: "8px", border: "1px solid rgba(0,234,255,0.1)", textAlign: "center" }}>
              <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", marginBottom: "6px" }}>CPU Load</div>
              <div style={{ fontSize: "28px", fontWeight: 800, color: "#4ade80" }}>{result.cpuPercent}%</div>
            </div>
            <div style={{ padding: "16px", background: "rgba(0,234,255,0.04)", borderRadius: "8px", border: "1px solid rgba(0,234,255,0.1)", textAlign: "center" }}>
              <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", marginBottom: "6px" }}>GPU Load</div>
              <div style={{ fontSize: "28px", fontWeight: 800, color: "#4ade80" }}>{result.gpuPercent}%</div>
            </div>
          </div>

          <div style={{ textAlign: "center", marginBottom: "20px" }}>
            <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", marginBottom: "6px" }}>Bottleneck</div>
            <div style={{
              display: "inline-block",
              padding: "8px 24px", borderRadius: "20px",
              fontSize: "18px", fontWeight: 800,
              background: result.severity === "Balanced" ? "rgba(74,222,128,0.1)" : result.severity === "Mild" ? "rgba(245,158,11,0.1)" : "rgba(239,68,68,0.1)",
              border: `1px solid ${result.severity === "Balanced" ? "rgba(74,222,128,0.3)" : result.severity === "Mild" ? "rgba(245,158,11,0.3)" : "rgba(239,68,68,0.3)"}`,
              color: result.severity === "Balanced" ? "#4ade80" : result.severity === "Mild" ? "#f59e0b" : "#ef4444"
            }}>
              {result.bottleneckPercent}% — {result.severity}
            </div>
          </div>

          <div style={{
            padding: "14px", borderRadius: "8px",
            background: "rgba(0,234,255,0.04)", border: "1px solid rgba(0,234,255,0.1)",
            fontSize: "13px", color: "#ccc", lineHeight: "1.6"
          }}>
            <strong style={{ color: "#00eaff" }}>Recommendation:</strong> {result.recommendation}
          </div>
        </div>
      )}

      {!result && (selectedCpu || selectedGpu) && (
        <div style={{ textAlign: "center", padding: "40px", color: "#555", fontSize: "13px" }}>
          Select both a CPU and GPU, then click "Calculate Bottleneck" to see results.
        </div>
      )}
    </div>
  );
}
