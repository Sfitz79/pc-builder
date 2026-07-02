import { useState, useEffect } from "react";
import { loadCSV } from "../utils/loadCSV";
import { compareComponents } from "../utils/compare";

const COMPARE_TYPES = [
  { id: "cpu", label: "CPU", file: "cpu.csv" },
  { id: "gpu", label: "GPU", file: "gpu.csv" },
  { id: "ram", label: "RAM", file: "ram.csv" },
  { id: "motherboard", label: "Motherboard", file: "motherboard.csv" },
  { id: "storage", label: "Storage", file: "storage.csv" },
  { id: "psu", label: "Power Supply", file: "power-supply.csv" },
];

const FIELD_LABELS = {
  core_count: "Cores", core_clock: "Base Clock", boost_clock: "Boost Clock",
  threads: "Threads", tdp: "TDP", socket: "Socket", microarchitecture: "Architecture",
  memory: "VRAM", chipset: "Chipset", memory_type: "Memory Type",
  speed: "Speed", modules: "Capacity", cas_latency: "CAS Latency", ram_type: "RAM Type",
  form_factor: "Form Factor", max_memory: "Max Memory",
  capacity: "Capacity", type: "Type", read_speed: "Read Speed", write_speed: "Write Speed",
  wattage: "Wattage", modular: "Modular", rating: "Rating",
  size: "Size", max_gpu_length: "Max GPU Length",
};

export default function ComponentComparison() {
  const [type, setType] = useState("cpu");
  const [items, setItems] = useState([]);
  const [item1, setItem1] = useState("");
  const [item2, setItem2] = useState("");
  const [result, setResult] = useState(null);

  useEffect(() => {
    const conf = COMPARE_TYPES.find(t => t.id === type);
    if (!conf) return;
    loadCSV(conf.file).then(all => {
      const valid = all.filter(i => i.name);
      setItems(valid);
    });
    setItem1("");
    setItem2("");
    setResult(null);
  }, [type]);

  const filtered1 = items.filter(i => (i.name || "").toLowerCase().includes(item1.toLowerCase()));
  const filtered2 = items.filter(i => (i.name || "").toLowerCase().includes(item2.toLowerCase()));

  const handleCompare = () => {
    const i1 = items.find(i => i.name === item1);
    const i2 = items.find(i => i.name === item2);
    if (!i1 || !i2) return;
    const res = compareComponents([i1, i2], type);
    setResult(res);
  };

  const label = (key) => FIELD_LABELS[key] || key.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase());

  return (
    <div style={{ maxWidth: "900px", margin: "0 auto" }}>
      <h1 style={{ fontSize: "24px", color: "#00eaff", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "6px" }}>
        Component Comparison
      </h1>
      <p style={{ color: "#888", fontSize: "13px", marginBottom: "24px" }}>
        Compare two components side by side to find the best fit for your build.
      </p>

      <div style={{ marginBottom: "20px" }}>
        <label style={{ fontSize: "12px", color: "#888", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "6px", display: "block" }}>
          Component Type
        </label>
        <div style={{ display: "flex", gap: "8px", flexWrap: "wrap" }}>
          {COMPARE_TYPES.map(t => (
            <button key={t.id}
              onClick={() => setType(t.id)}
              className={`button ${type === t.id ? "" : "secondary"} small`}
            >
              {t.label}
            </button>
          ))}
        </div>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "20px", marginBottom: "20px" }}>
        <div>
          <label style={{ fontSize: "12px", color: "#888", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "6px", display: "block" }}>
            Component 1
          </label>
          <input type="text" placeholder={`Search ${type.toUpperCase()}...`}
            value={item1} onChange={e => setItem1(e.target.value)}
            style={{ width: "100%", marginBottom: "8px" }}
          />
          <select value={item1} onChange={e => setItem1(e.target.value)}
            style={{ width: "100%", height: "48px" }}
          >
            <option value="">— Select —</option>
            {filtered1.map(i => <option key={i.name} value={i.name}>{i.name}</option>)}
          </select>
        </div>
        <div>
          <label style={{ fontSize: "12px", color: "#888", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "6px", display: "block" }}>
            Component 2
          </label>
          <input type="text" placeholder={`Search ${type.toUpperCase()}...`}
            value={item2} onChange={e => setItem2(e.target.value)}
            style={{ width: "100%", marginBottom: "8px" }}
          />
          <select value={item2} onChange={e => setItem2(e.target.value)}
            style={{ width: "100%", height: "48px" }}
          >
            <option value="">— Select —</option>
            {filtered2.map(i => <option key={i.name} value={i.name}>{i.name}</option>)}
          </select>
        </div>
      </div>

      <button onClick={handleCompare} disabled={!item1 || !item2}
        className="button" style={{ width: "100%", marginBottom: "24px" }}>
        Compare
      </button>

      {result && (
        <div style={{ background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.15)", overflow: "hidden" }}>
          <div style={{ overflowX: "auto" }}>
            <table className="builder-table" style={{ marginBottom: 0, border: "none" }}>
              <thead>
                <tr>
                  <th style={{ width: "200px", minWidth: "160px" }}>Specification</th>
                  {result.items.map((item, i) => (
                    <th key={i} style={{ textAlign: "center", minWidth: "200px" }}>{item.name}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {result.keys.map(key => {
                  const vals = result.items.map(r => r[key]);
                  const bestVal = result.bestValues?.[key];
                  return (
                    <tr key={key}>
                      <td style={{ fontWeight: 600, color: "#ccc" }}>{label(key)}</td>
                      {vals.map((v, i) => {
                        const isBest = bestVal !== undefined && v === bestVal;
                        return (
                          <td key={i} style={{
                            textAlign: "center",
                            color: isBest ? "#4ade80" : "#e6e6e6",
                            fontWeight: isBest ? 700 : 400
                          }}>
                            {v}
                            {isBest && <span style={{ marginLeft: "6px", fontSize: "10px", color: "#4ade80" }}>★ Best</span>}
                          </td>
                        );
                      })}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
