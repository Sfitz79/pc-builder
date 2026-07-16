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
  "case-accessory": "Case Accessory", "external-hard-drive": "External HDD",
  streaming: "Streaming", "game-controllers": "Game Controllers",
  "flight-simulation": "Flight Sim", "racing-simulation": "Racing Sim",
  "cables-and-accessories": "Cables & Acc.",
};

const FULL_SERVICES = [
  { name: "PCTG Build, Premium Cable Manage, Premium Test & Optimize", price: 150 },
  { name: "2 Year Warranty & Free Tech Support / Remote Assistance", price: 0 },
  { name: "Delivery By RM Special Delivery Insured", price: 50 },
  { name: "Windows 11 Pro Retail", price: 35 }
];

function getSelectionEntries(selections) {
  const entries = [];
  for (const [cat, val] of Object.entries(selections)) {
    if (Array.isArray(val)) {
      val.forEach(item => entries.push({ cat, item }));
    } else if (val) {
      entries.push({ cat, item: val });
    }
  }
  return entries;
}

function computeBreakdown(selections, type) {
  const isFull = type === "full";
  const services = isFull ? FULL_SERVICES : [];
  const items = [];

  for (const { cat, item } of getSelectionEntries(selections)) {
    if (cat === "os" && isFull) continue;
    const qty = item.qty || 1;
    const price = parseFloat(item?.price) || 0;
    const label = CATEGORY_LABELS[cat] || cat;
    items.push({
      label,
      name: item?.name || "—",
      price,
      qty,
      lineTotal: price * qty,
    });
  }
  for (const s of services) {
    items.push({ label: "", name: s.name, price: parseFloat(s.price) || 0, qty: 1, lineTotal: parseFloat(s.price) || 0 });
  }

  const componentsTotal = Object.entries(selections).reduce((sum, [cat, val]) => {
    if (cat === "os" && isFull) return sum;
    if (Array.isArray(val)) {
      return sum + val.reduce((s, i) => s + (parseFloat(i?.price) || 0) * (i.qty || 1), 0);
    }
    const price = parseFloat(val?.price) || 0;
    const qty = val?.qty || 1;
    return sum + price * qty;
  }, 0);
  const total = isFull ? Math.ceil(componentsTotal * 1.03) : componentsTotal;
  const surcharge = isFull ? componentsTotal * 0.03 : 0;

  return { items, componentsTotal, surcharge, total };
}

export default function PriceBreakdown({ type: forcedType }) {
  const selections = usePCStore(s => s.selections);
  const storeType = usePCStore(s => s.buildType);
  const type = forcedType || storeType;
  const isFull = type === "full";
  const { items, total } = computeBreakdown(selections, type);

  return (
    <div className="price-breakdown">
      <div style={{ fontSize: "11px", color: "#00eaff", textTransform: "uppercase", letterSpacing: "0.8px", marginBottom: "8px", fontWeight: 700 }}>
        Full Build — Complete Bundle Price
      </div>
      <table style={{ width: "100%", borderCollapse: "collapse", fontSize: "12px" }}>
        <thead>
          <tr style={{ borderBottom: "1px solid rgba(255,255,255,0.06)" }}>
            <th style={{ textAlign: "left", padding: "8px 12px", color: "#555", fontWeight: 600, fontSize: "10px", textTransform: "uppercase" }}>Item</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item, i) => (
            <tr key={i} style={{ borderBottom: "1px solid rgba(255,255,255,0.03)" }}>
              <td style={{ padding: "4px 6px", color: "#999", fontSize: "11px" }}>
                {item.label && <span style={{ color: "#777", fontWeight: 600, marginRight: "4px" }}>{item.label}:</span>}
                {item.name.length > 40 ? item.name.slice(0, 40) + "…" : item.name}
                {item.qty > 1 && <span style={{ color: "#00eaff", marginLeft: "4px" }}>×{item.qty}</span>}
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <td style={{ padding: "6px 6px", color: "#00eaff", fontSize: "13px", fontWeight: 700, borderTop: "1px solid rgba(0,234,255,0.2)" }}>
              Total Bundled Price
            </td>
          </tr>
          <tr>
            <td style={{ padding: "2px 6px 6px", color: "#00eaff", fontSize: "15px", fontWeight: 800 }}>
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
    <div style={{ padding: "14px", background: "rgba(0,234,255,0.04)", borderRadius: "8px", border: "1px solid rgba(0,234,255,0.15)" }}>
      <PriceBreakdown type="full" />
    </div>
  );
}
