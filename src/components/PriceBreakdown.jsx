import { usePCStore } from "../store/usePCStore";

const CATEGORY_LABELS = {
  case: "Case", cooler: "Cooler", cpu: "CPU", motherboard: "Motherboard",
  ram: "RAM", storage: "Storage", gpu: "GPU", psu: "PSU", os: "OS",
  monitor: "Monitor", keyboard: "Keyboard", mouse: "Mouse",
  speakers: "Speakers", headphones: "Headphones", webcam: "Webcam",
  "case-fan": "Case Fan", "thermal-paste": "Thermal Paste",
  "optical-drive": "Optical Drive", "wired-network-card": "Wired NIC",
  "wireless-network-card": "WiFi Card", "sound-card": "Sound Card",
  "fan-controller": "Fan Controller", ups: "UPS",
  "case-accessory": "Case Accessory", "external-hard-drive": "External HDD"
};

const FULL_SERVICES = [
  { name: "PCTG Build, Premium Cable Manage, Premium Test & Optimize", price: 150 },
  { name: "2 Year Warranty & Free Tech Support / Remote Assistance", price: 0 },
  { name: "Delivery By RM Special Delivery Insured", price: 50 },
  { name: "Windows 11 Pro Retail", price: 35 }
];

const PARTS_SERVICES = [
  { name: "2 Year Warranty & Free Tech Support / Remote Assistance", price: 0 },
  { name: "Windows 11 Pro Retail", price: 35 },
  { name: "System Design Charge", price: 35 }
];

function computeBreakdown(selections, type) {
  const isFull = type === "full";
  const services = isFull ? FULL_SERVICES : PARTS_SERVICES;
  const items = [];

  for (const [cat, item] of Object.entries(selections)) {
    if (cat === "os" && isFull) continue;
    items.push({ label: CATEGORY_LABELS[cat] || cat, name: item?.name || "—", price: parseFloat(item?.price) || 0 });
  }
  for (const s of services) {
    items.push({ label: "", name: s.name, price: s.price });
  }

  const componentsTotal = items.reduce((sum, i) => sum + i.price, 0);
  const surcharge = isFull ? componentsTotal * 0.03 : 0;
  const total = isFull ? Math.ceil(componentsTotal * 1.03) : componentsTotal;

  return { items, componentsTotal, surcharge, total };
}

export default function PriceBreakdown({ type: forcedType }) {
  const selections = usePCStore(s => s.selections);
  const storeType = usePCStore(s => s.buildType);
  const adminMode = usePCStore(s => s.adminMode);
  const type = forcedType || storeType;
  const isFull = type === "full";
  const { items, componentsTotal, surcharge, total } = computeBreakdown(selections, type);

  return (
    <div className="price-breakdown">
      <div style={{ fontSize: "11px", color: isFull ? "#00eaff" : "#ff005e", textTransform: "uppercase", letterSpacing: "0.8px", marginBottom: "8px", fontWeight: 700 }}>
        {isFull ? "Full Build — Complete Bundle Price" : "Parts Only — Complete Pricing"}
      </div>
      <table style={{ width: "100%", borderCollapse: "collapse", fontSize: "12px" }}>
        <thead>
          <tr style={{ borderBottom: "1px solid rgba(255,255,255,0.06)" }}>
            <th style={{ textAlign: "left", padding: "4px 6px", color: "#555", fontWeight: 600, fontSize: "10px", textTransform: "uppercase" }}>Item</th>
            <th style={{ textAlign: "right", padding: "4px 6px", color: "#555", fontWeight: 600, fontSize: "10px", textTransform: "uppercase", width: "70px" }}>Price</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item, i) => (
            <tr key={i} style={{ borderBottom: "1px solid rgba(255,255,255,0.03)" }}>
              <td style={{ padding: "4px 6px", color: "#999", fontSize: "11px" }}>
                {item.label && <span style={{ color: "#777", fontWeight: 600, marginRight: "4px" }}>{item.label}:</span>}
                {item.name.length > 50 ? item.name.slice(0, 50) + "…" : item.name}
              </td>
              <td style={{ padding: "4px 6px", textAlign: "right", color: "#ccc", fontWeight: 600 }}>
                <span className={adminMode ? "" : "blur-data"}>£{item.price.toFixed(2)}</span>
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr style={{ borderTop: "1px solid rgba(255,255,255,0.1)" }}>
            <td style={{ padding: "5px 6px", color: "#888", fontSize: "11px" }}>Subtotal</td>
            <td style={{ padding: "5px 6px", textAlign: "right", color: "#ccc", fontWeight: 600, fontSize: "12px" }}>
              <span className={adminMode ? "" : "blur-data"}>£{componentsTotal.toFixed(2)}</span>
            </td>
          </tr>
          {isFull && (
            <tr>
              <td style={{ padding: "4px 6px", color: "#888", fontSize: "11px" }}>3% Card Processing Surcharge</td>
              <td style={{ padding: "4px 6px", textAlign: "right", color: "#ccc", fontWeight: 600, fontSize: "12px" }}>
                <span className={adminMode ? "" : "blur-data"}>£{surcharge.toFixed(2)}</span>
              </td>
            </tr>
          )}
          <tr>
            <td style={{ padding: "6px 6px", color: isFull ? "#00eaff" : "#ff005e", fontSize: "13px", fontWeight: 700, borderTop: "1px solid rgba(0,234,255,0.2)" }}>
              {isFull ? "Total Bundled Price" : "Total Parts Price"}
            </td>
              <td style={{ padding: "6px 6px", textAlign: "right", color: isFull ? "#00eaff" : "#ff005e", fontSize: "15px", fontWeight: 800, borderTop: "1px solid rgba(0,234,255,0.2)" }}>
                £{total.toLocaleString('en-GB')}
              </td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}

export function PriceBreakdownPair({ selections, adminMode }) {
  return (
    <div style={{ display: "flex", gap: "16px", flexWrap: "wrap" }}>
      <div style={{ flex: "1 1 300px", padding: "14px", background: "rgba(0,234,255,0.04)", borderRadius: "8px", border: "1px solid rgba(0,234,255,0.15)" }}>
        <PriceBreakdown type="full" />
      </div>
      <div style={{ flex: "1 1 300px", padding: "14px", background: "rgba(255,0,94,0.04)", borderRadius: "8px", border: "1px solid rgba(255,0,94,0.15)" }}>
        <PriceBreakdown type="parts" />
      </div>
    </div>
  );
}
