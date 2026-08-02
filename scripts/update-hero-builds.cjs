const fs = require("fs");
const path = require("path");

const ROOT = path.join(__dirname, "..");

// ── GPU spec lookup ────────────────────────────────────────────────
const GPU_SPECS = {
  "Sparkle ROC OC":                     { chipset: "Intel Arc A750",           vram: "8GB GDDR6" },
  "Palit Infinity 3":                   { chipset: "NVIDIA GeForce RTX 5060",  vram: "8GB GDDR6" },
  "Gainward Python III OC":             { chipset: "NVIDIA GeForce RTX 5060 Ti", vram: "16GB GDDR6" },
  "PowerColor Hellhound":               { chipset: "AMD Radeon RX 7800 XT",    vram: "16GB GDDR6" },
  "MSI SUPRIM LIQUID SOC":              { chipset: "NVIDIA GeForce RTX 5080",  vram: "16GB GDDR7" },
  "ASRock Challenger OC":               { chipset: "AMD Radeon RX 7600",       vram: "8GB GDDR6" },
  "Intel Limited Edition":              { chipset: "Intel Arc B580",           vram: "12GB GDDR6" },
  "Zotac GAMING Twin Edge OC":          { chipset: "NVIDIA GeForce RTX 5070",  vram: "12GB GDDR7" },
  "Asus ROG STRIX OC EVA":             { chipset: "NVIDIA GeForce RTX 3080 LHR", vram: "12GB GDDR6X" },
  "Zotac Twin Edge OC":                 { chipset: "NVIDIA GeForce RTX 4060",  vram: "8GB GDDR6" },
  "Zotac GAMING SOLID":                 { chipset: "NVIDIA GeForce RTX 4070 Ti SUPER", vram: "16GB GDDR6X" },
  "Gigabyte GAMING OC":                 { chipset: "NVIDIA GeForce RTX 4090",  vram: "24GB GDDR6X" },
  "Asus ProArt OC":                     { chipset: "NVIDIA GeForce RTX 4080 SUPER", vram: "16GB GDDR6X" },
  "Acer Predator BiFrost OC":           { chipset: "Intel Arc A750",           vram: "8GB GDDR6" },
  "AMD Radeon Pro W7900":              { chipset: "AMD Radeon Pro W7900",     vram: "48GB GDDR6" },
  "NVIDIA RTX 6000 Ada":               { chipset: "NVIDIA RTX 6000 Ada",      vram: "48GB GDDR6" },
};

// ── SSD spec lookup (name → capacity + interface) ──────────────────
// Keyed by exact name from hero-builds.csv
const SSD_SPECS = {
  "Kingston NV2":                       { cap: "500GB",  iface: "NVMe PCIe 4.0" },
  "PNY CS2230":                         { cap: "1TB",    iface: "NVMe PCIe 4.0" },
  "TEAMGROUP T-Create Classic":         { cap: "1TB",    iface: "NVMe PCIe 4.0" },
  "Silicon Power Ace A5X":              { cap: "2TB",    iface: "M.2 SATA" },
  "Crucial T500":                       { cap: "2TB",    iface: "NVMe PCIe 4.0" },
  // Two PNY CS2140 entries distinguished by price
  "PNY CS2140":                         null, // handled by price
  "Samsung 980 Pro w/Heatsink":         null, // handled by price
  "Crucial P3 Plus":                    { cap: "2TB",    iface: "NVMe PCIe 4.0" },
  "TEAMGROUP GX2":                      { cap: "2TB",    iface: "M.2 SATA" },
  "Western Digital Blue SN580":         { cap: "2TB",    iface: "NVMe PCIe 4.0" },
  "PNY CS1030":                         { cap: "1TB",    iface: "NVMe PCIe 3.0" },
  "Crucial E100":                       { cap: "2TB",    iface: "NVMe PCIe 4.0" },
  "Patriot P400 V4":                    { cap: "4TB",    iface: "NVMe PCIe 4.0" },
  "ADATA Legend 800":                   { cap: "2TB",    iface: "NVMe PCIe 4.0" },
  "Integral INSSD1TM242":              { cap: "1TB",    iface: "M.2 SATA" },
  "Kingston KC600":                     { cap: "1TB",    iface: "M.2 SATA" },
  "FanXiang S770":                      { cap: "4TB",    iface: "NVMe PCIe 4.0" },
  "Western Digital Blue SN550":         { cap: "1TB",    iface: "NVMe PCIe 3.0" },
  "Micron 9400 Pro 7.68TB":            { cap: "7.68TB", iface: "NVMe PCIe 4.0" },
  "Samsung 990 Pro 2TB (×4)":          { cap: "2TB ×4", iface: "NVMe PCIe 4.0" },
};

function getSsdSpec(name, price) {
  if (name === "PNY CS2140" && price < 40) return { cap: "500GB", iface: "NVMe PCIe 4.0" };
  if (name === "PNY CS2140") return { cap: "1TB", iface: "NVMe PCIe 4.0" };
  if (name === "Samsung 980 Pro w/Heatsink" && price < 100) return { cap: "1TB", iface: "NVMe PCIe 4.0" };
  if (name === "Samsung 980 Pro w/Heatsink") return { cap: "2TB", iface: "NVMe PCIe 4.0" };
  const spec = SSD_SPECS[name];
  if (spec) return spec;
  return null;
}

// ── RAM spec lookup ────────────────────────────────────────────────
const RAM_SPECS = {
  "TEAMGROUP Elite 16 GB":                    { spec: "16GB DDR5-5200 (1×16GB)" },
  "Kingston FURY Beast RGB 32 GB":            null, // price-dependent
  "TEAMGROUP Elite 32 GB":                    { spec: "32GB DDR5-5200 (1×32GB)" },
  "Kingston FURY Beast 32 GB":                { spec: "32GB DDR5-5600 (1×32GB)" },
  "G.Skill Ripjaws S5 128 GB":               { spec: "128GB DDR5-6400 (2×64GB)" },
  "Kingston FURY Renegade Pro 32 GB":         { spec: "32GB DDR5-5600 (1×32GB)" },
  "Corsair Vengeance RGB 64 GB":              { spec: "64GB DDR5-5600 (2×32GB)" },
  "Silicon Power Value Gaming 32 GB":         { spec: "32GB DDR5-6000 (2×16GB)" },
  "Samsung DDR5 ECC 512GB (8×64GB)":         { spec: "512GB DDR5 ECC (8×64GB)" },
  "Samsung DDR5 ECC 256GB (4×64GB)":         { spec: "256GB DDR5 ECC (4×64GB)" },
};

function getRamSpec(name, price) {
  if (name === "Kingston FURY Beast RGB 32 GB" && price < 100) return "32GB DDR5-5600 (1×32GB)";
  if (name === "Kingston FURY Beast RGB 32 GB") return "32GB DDR5-6000 (1×32GB)";
  const spec = RAM_SPECS[name];
  return spec ? spec.spec : name;
}

// ── PSU spec lookup ────────────────────────────────────────────────
const PSU_SPECS = {
  "Antec CSK":                          "Antec CSK 550W 80+ Bronze",
  "Cooler Master MWE Bronze 750 V3":    "Cooler Master MWE Bronze 750 V3 750W 80+ Bronze 230V",
  "be quiet! System Power 10":          "be quiet! System Power 10 550W 80+ Bronze",
  "be quiet! Pure Power 12 M":          "be quiet! Pure Power 12 M 750W 80+ Gold",
  "Corsair RM1000x (2021)":            "Corsair RM1000x (2021) 1000W 80+ Gold",
  "MSI MAG A850GL PCIE5":              "MSI MAG A850GL PCIE5 850W 80+ Gold",
  "be quiet! Straight Power 11 1000W":  "be quiet! Straight Power 11 1000W 80+ Gold",
  "Corsair SF850L":                     "Corsair SF850L 850W 80+ Gold SFX",
  "Corsair HX1500i (2023)":            "Corsair HX1500i (2023) 1500W 80+ Platinum",
  "Lian Li EDGE":                       "Lian Li EDGE 850W 80+ Gold",
  "Cooler Master V SFX Platinum":       "Cooler Master V SFX Platinum 850W 80+ Platinum SFX",
  "Corsair AX1600i 1600W":             "Corsair AX1600i 1600W 80+ Titanium",
};

// ── Helpers ────────────────────────────────────────────────────────
function parseCSVLine(line) {
  const values = []; let cur = ""; let inQ = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (ch === '"') { if (inQ && line[i+1] === '"') { cur += '"'; i++; } else inQ = !inQ; continue; }
    if (ch === ',' && !inQ) { values.push(cur); cur = ""; continue; }
    cur += ch;
  }
  values.push(cur);
  return values;
}

function csvEscape(v) {
  const s = String(v ?? "");
  if (s.includes(",") || s.includes('"') || s.includes("\n"))
    return '"' + s.replace(/"/g, '""') + '"';
  return s;
}

function calcBundled(componentsTotal) {
  const mandatory = 150 + 35 + 50; // build + OS + delivery
  const subtotal = componentsTotal + mandatory;
  const surcharge = subtotal * 0.03;
  return Math.ceil(subtotal + surcharge);
}

// ── Normalize previously enriched names (idempotent) ───────────────
function stripEnrichedSuffix(name) {
  let r = name;
  // Strip interface suffixes
  r = r.replace(/\s+NVMe PCIe \d+\.\d$/i, '');
  r = r.replace(/\s+M\.2 SATA$/i, '');
  r = r.replace(/\s+NVMe$/i, '');
  // Strip VRAM suffixes (GPU)
  r = r.replace(/\s+\d+GB (GDDR[56]X?|GDDR7)$/i, '');
  // Strip duplicate capacity patterns: "7.68TB 7.68TB" → "7.68TB"
  r = r.replace(/(\d+(\.\d+)?\s*(TB|GB|MB))\s+\1/i, '$1');
  // Strip " 2TB ×4" duplicate from SSD
  r = r.replace(/\s+(\d+TB)\s+×(\d+)$/i, '');
  return r;
}

// ── Load hero-builds.csv ──────────────────────────────────────────
const csvText = fs.readFileSync(path.join(ROOT, "hero-builds.csv"), "utf-8");
const csvLines = csvText.split(/\r?\n/).filter(Boolean);
const csvHeaders = parseCSVLine(csvLines[0]);
const builds = csvLines.slice(1).map(line => {
  const vals = parseCSVLine(line);
  const o = {};
  csvHeaders.forEach((h, i) => o[h] = vals[i] ?? "");
  return o;
});

// ── Enrich names ──────────────────────────────────────────────────
function enrichGpu(name) {
  // First, strip any previous enrichment
  const clean = stripEnrichedSuffix(name);
  // Handle multi-GPU names like "AMD Radeon Pro W7900 (×2)"
  const multiMatch = clean.match(/^(.+?)\s*\(×(\d+)\)$/);
  const baseName = multiMatch ? multiMatch[1] : clean;
  const suffix = multiMatch ? " (×" + multiMatch[2] + ")" : "";
  const spec = GPU_SPECS[baseName];
  if (!spec) return name;
  return spec.chipset + " " + spec.vram + suffix;
}

function enrichSsd(name, price) {
  // First, strip any previous enrichment
  const clean = stripEnrichedSuffix(name);
  const p = parseFloat(price) || 0;
  const spec = getSsdSpec(clean, p);
  if (!spec) return name;
  // Don't add capacity if already present in name
  const nameHasCapacity = /\d+(\.\d+)?\s*(TB|GB|MB)/i.test(clean);
  if (nameHasCapacity) return clean + " " + spec.iface;
  return clean + " " + spec.cap + " " + spec.iface;
}

function enrichRam(name, price) {
  const clean = stripEnrichedSuffix(name);
  return getRamSpec(clean, parseFloat(price) || 0);
}

function enrichPsu(name) {
  // Try exact match first, then try without " 230V" suffix
  if (PSU_SPECS[name]) return PSU_SPECS[name];
  const stripped = name.replace(/\s+230V$/i, '');
  if (PSU_SPECS[stripped]) return PSU_SPECS[stripped];
  return name;
}

// ── Build enriched data ───────────────────────────────────────────
const enriched = builds.map(b => {
  const components = [
    parseFloat(b.cpu_price) || 0,
    parseFloat(b.gpu_price) || 0,
    parseFloat(b.motherboard_price) || 0,
    parseFloat(b.ram_price) || 0,
    parseFloat(b.ssd_name && b.ssd_name.includes("9400") ? b.ssd_price : b.ssd_price) || 0,
    parseFloat(b.case_price) || 0,
    parseFloat(b.cooler_price) || 0,
    parseFloat(b.psu_price) || 0,
  ];
  const componentTotal = components.reduce((a, c) => a + c, 0);
  const bundled = calcBundled(componentTotal);

  return {
    name: b.build_name,
    scenario: b.scenario,
    cpu: b.cpu_name,
    gpu: enrichGpu(b.gpu_name),
    mb: b.motherboard_name,
    ram: enrichRam(b.ram_name, b.ram_price),
    ssd: enrichSsd(b.ssd_name, b.ssd_price),
    case_: b.case_name,
    cooler: b.cooler_name,
    psu: enrichPsu(b.psu_name),
    componentTotal,
    bundled,
  };
});

// ── Generate HTML ─────────────────────────────────────────────────
const scenarioCounts = {};
enriched.forEach(b => { scenarioCounts[b.scenario] = (scenarioCounts[b.scenario] || 0) + 1; });

let html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PCTG Hero Builds</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { background: #080812; color: #e0e0e0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; min-height: 100vh; }

  .header { text-align: center; padding: 40px 20px 20px; }
  .header h1 { font-size: 28px; color: #00eaff; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 8px; }
  .header p { color: #666; font-size: 13px; }

  .filters { display: flex; justify-content: center; gap: 8px; padding: 16px 20px 24px; flex-wrap: wrap; }
  .filter-btn { padding: 8px 18px; border-radius: 20px; border: 1px solid rgba(0,234,255,0.15); background: rgba(0,234,255,0.04); color: #888; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; text-transform: uppercase; letter-spacing: 0.5px; }
  .filter-btn:hover { border-color: rgba(0,234,255,0.3); color: #00eaff; }
  .filter-btn.active { background: rgba(0,234,255,0.12); border-color: #00eaff; color: #00eaff; }

  .grid { max-width: 1200px; margin: 0 auto; padding: 0 20px 60px; display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }

  .build-card { background: #0d0d18; border: 1px solid rgba(0,234,255,0.08); border-radius: 12px; overflow: hidden; transition: all 0.3s; }
  .build-card:hover { border-color: rgba(0,234,255,0.25); transform: translateY(-2px); box-shadow: 0 8px 30px rgba(0,234,255,0.06); }
  .build-card.hidden { display: none; }

  .card-header { padding: 16px 18px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); }
  .card-scenario { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #00eaff; font-weight: 600; margin-bottom: 4px; }
  .card-name { font-size: 16px; font-weight: 700; color: #fff; }

  .card-price { padding: 12px 18px; background: linear-gradient(135deg, rgba(0,234,255,0.04), rgba(74,222,128,0.04)); text-align: center; }
  .card-price .label { font-size: 10px; color: #555; text-transform: uppercase; letter-spacing: 1px; }
  .card-price .amount { font-size: 26px; font-weight: 800; color: #fff; }
  .card-price .sub { font-size: 10px; color: #555; }

  .card-parts { padding: 8px 0; }
  .part-row { display: flex; align-items: center; gap: 10px; padding: 6px 18px; }
  .part-row:hover { background: rgba(255,255,255,0.02); }
  .part-label { font-size: 10px; color: #555; text-transform: uppercase; width: 72px; flex-shrink: 0; letter-spacing: 0.5px; }
  .part-name { font-size: 12px; color: #ccc; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .part-row.highlight .part-name { color: #fff; font-weight: 600; }

  .tier-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; margin-left: 8px; vertical-align: middle; }
  .tier-budget { background: rgba(74,222,128,0.12); color: #4ade80; }
  .tier-mid { background: rgba(0,234,255,0.12); color: #00eaff; }
  .tier-high { background: rgba(255,0,94,0.12); color: #ff005e; }
  .tier-ultimate { background: rgba(168,85,247,0.12); color: #a855f7; }

  .card-summary { padding: 10px 18px 4px; font-size: 13px; color: #aaa; font-style: italic; border-top: 1px solid rgba(255,255,255,0.04); }
  .card-desc { padding: 4px 18px 8px; font-size: 11px; color: #555; line-height: 1.5; }

  .legend { max-width: 1200px; margin: 0 auto; padding: 0 20px 40px; display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; }
  .legend-item { display: flex; align-items: center; gap: 6px; font-size: 11px; color: #666; }

  @media (max-width: 600px) {
    .grid { grid-template-columns: 1fr; }
    .header h1 { font-size: 20px; }
  }
</style>
</head>
<body>

<div class="header">
  <h1>PCTG Hero Builds</h1>
  <p>${enriched.length} curated builds across 5 scenarios — real parts, real prices, compatibility checked. Prices include build, testing, OS, delivery & 3% processing fee.</p>
</div>

<div class="legend">
  <div class="legend-item"><span class="tier-badge tier-budget">Budget</span> Under £750</div>
  <div class="legend-item"><span class="tier-badge tier-mid">Mid</span> £750–£1,500</div>
  <div class="legend-item"><span class="tier-badge tier-high">High</span> £1,500–£3,500</div>
  <div class="legend-item"><span class="tier-badge tier-ultimate">Ultimate</span> £3,500+</div>
</div>

<div class="filters">
  <button class="filter-btn active" data-filter="all">All (${enriched.length})</button>
`;

const scenarioOrder = ["Gaming", "Streaming", "Workstation", "Content Creation", "General / Office"];
scenarioOrder.forEach(s => {
  if (scenarioCounts[s]) {
    html += `  <button class="filter-btn" data-filter="${s}">${s} (${scenarioCounts[s]})</button>\n`;
  }
});

html += `</div>

<div class="grid" id="grid"></div>

<script>
const builds = [
`;

enriched.forEach(b => {
  const esc = s => String(s).replace(/"/g, '\\"').replace(/\n/g, '\\n');
  const parts = [
    "CPU: " + b.cpu,
    b.gpu ? "GPU: " + b.gpu : null,
    "Motherboard: " + b.mb,
    "RAM: " + b.ram,
    "SSD: " + b.ssd,
    "Case: " + b.case_,
    "CPU Cooler: " + b.cooler,
    "PSU: " + b.psu,
  ].filter(Boolean);
  const desc = parts.join(" | ");
  function htmlTier(total) {
    if (total < 750) return "budget";
    if (total < 1500) return "mid";
    if (total < 3500) return "pro";
    return "ultimate";
  }
  const tierLabel = htmlTier(b.bundled);
  const summary = b.name.includes("Server")
    ? "Enterprise server workstation for virtualization, simulation, and data-intensive workloads."
    : b.gpu
    ? (b.scenario === "Gaming" ? "Gaming PC built for " + tierLabel + "-level performance."
      : b.scenario === "Streaming" ? "Streaming PC built for " + tierLabel + "-quality content creation."
      : b.scenario === "Workstation" ? "Workstation built for " + tierLabel + "-level professional work."
      : "Creator PC built for " + tierLabel + "-level production work.")
    : "Office PC built for everyday productivity and multitasking.";
  html += `  { name:"${esc(b.name)}", scenario:"${esc(b.scenario)}", cpu:"${esc(b.cpu)}", gpu:"${esc(b.gpu)}", mb:"${esc(b.mb)}", ram:"${esc(b.ram)}", ssd:"${esc(b.ssd)}", case_:"${esc(b.case_)}", cooler:"${esc(b.cooler)}", psu:"${esc(b.psu)}", total:${b.componentTotal}, summary:"${esc(summary)}", desc:"${esc(desc)}" },\n`;
});

html += `];

function calcBundled(componentsTotal) {
  const mandatory = 150 + 35 + 50;
  const subtotal = componentsTotal + mandatory;
  const surcharge = subtotal * 0.03;
  return Math.ceil(subtotal + surcharge);
}

function getTier(total) {
  if (total < 750) return { label: "Budget", cls: "tier-budget" };
  if (total < 1500) return { label: "Mid", cls: "tier-mid" };
  if (total < 3500) return { label: "High-End", cls: "tier-high" };
  return { label: "Ultimate", cls: "tier-ultimate" };
}

function renderBuilds(filter) {
  const grid = document.getElementById("grid");
  grid.innerHTML = "";
  builds.forEach(b => {
    if (filter !== "all" && b.scenario !== filter) return;
    const bundled = calcBundled(b.total);
    const tier = getTier(bundled);
    const card = document.createElement("div");
    card.className = "build-card";
    card.dataset.scenario = b.scenario;
    card.innerHTML = \`
      <div class="card-header">
        <div class="card-scenario">\${b.scenario} <span class="tier-badge \${tier.cls}">\${tier.label}</span></div>
        <div class="card-name">\${b.name}</div>
      </div>
      <div class="card-price">
        <div class="label">Price</div>
        <div class="amount">£\${bundled.toLocaleString("en-GB")}</div>
        <div class="sub">Incl. build, OS, delivery & 3% processing</div>
      </div>
      <div class="card-summary">\${b.summary}</div>
      <div class="card-parts">
        <div class="part-row highlight"><span class="part-label">CPU</span><span class="part-name">\${b.cpu}</span></div>
        <div class="part-row highlight"><span class="part-label">GPU</span><span class="part-name">\${b.gpu || "N/A"}</span></div>
        <div class="part-row"><span class="part-label">Motherboard</span><span class="part-name">\${b.mb}</span></div>
        <div class="part-row"><span class="part-label">RAM</span><span class="part-name">\${b.ram}</span></div>
        <div class="part-row"><span class="part-label">SSD</span><span class="part-name">\${b.ssd}</span></div>
        <div class="part-row"><span class="part-label">Case</span><span class="part-name">\${b.case_}</span></div>
        <div class="part-row"><span class="part-label">Cooler</span><span class="part-name">\${b.cooler}</span></div>
        <div class="part-row"><span class="part-label">PSU</span><span class="part-name">\${b.psu}</span></div>
      </div>
      <div class="card-desc">\${b.desc}</div>\`;
    grid.appendChild(card);
  });
}

document.querySelectorAll(".filter-btn").forEach(btn => {
  btn.addEventListener("click", () => {
    document.querySelectorAll(".filter-btn").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    renderBuilds(btn.dataset.filter);
  });
});

renderBuilds("all");
</script>
</body>
</html>`;

fs.writeFileSync(path.join(ROOT, "hero-builds.html"), html, "utf-8");
console.log("hero-builds.html written (" + enriched.length + " builds)");

// ── Generate hero-builds.csv (original names preserved, no monitors, bundled prices) ──
const csvOut = ["build_name,scenario,price_tier,cpu_name,cpu_price,gpu_name,gpu_price,motherboard_name,motherboard_price,ram_name,ram_price,ssd_name,ssd_price,case_name,case_price,cooler_name,cooler_price,psu_name,psu_price,total_price"];

builds.forEach((b, i) => {
  const e = enriched[i];
  const row = [
    csvEscape(b.build_name),
    csvEscape(b.scenario),
    csvEscape(b.price_tier),
    csvEscape(b.cpu_name),
    csvEscape(b.cpu_price),
    csvEscape(b.gpu_name),
    csvEscape(b.gpu_price),
    csvEscape(b.motherboard_name),
    csvEscape(b.motherboard_price),
    csvEscape(b.ram_name),
    csvEscape(b.ram_price),
    csvEscape(b.ssd_name),
    csvEscape(b.ssd_price),
    csvEscape(b.case_name),
    csvEscape(b.case_price),
    csvEscape(b.cooler_name),
    csvEscape(b.cooler_price),
    csvEscape(b.psu_name),
    csvEscape(b.psu_price),
    csvEscape(e.bundled),
  ];
  csvOut.push(row.join(","));
});

fs.writeFileSync(path.join(ROOT, "hero-builds.csv"), csvOut.join("\n") + "\n", "utf-8");
console.log("hero-builds.csv written (" + csvOut.length + " lines incl header)");

// ── Generate hero-builds-import.csv ────────────────────────────────
// 42-column format with Summary, SEO Keywords, Feature Highlights, Comparison fields
const importRows = [];

const TIER_MAP = {
  "Budget Gaming": "Budget", "1080p Gaming": "Mid", "1440p Gaming": "Pro",
  "4K Gaming": "High-End", "Ultimate Gaming": "Ultimate",
  "Budget Streamer": "Budget", "1080p Streamer": "Mid", "1440p Streamer": "Pro",
  "Pro Streamer": "High-End", "Ultimate Streamer": "Ultimate",
  "Budget Workstation": "Budget", "Mid Workstation": "Mid", "Pro Workstation": "Pro",
  "High-End Workstation": "High-End", "Ultimate Workstation": "Ultimate",
  "PCTG Server Workstation Xeon": "Server", "PCTG Server Workstation TRX": "Server",
  "Budget Creator": "Budget", "YouTube Creator": "Mid", "Pro Video Editor": "Pro",
  "Studio Workstation": "High-End", "Ultimate Creator": "Ultimate",
  "Basic Home PC": "Budget", "Office Productivity": "Budget",
  "Home Office Pro": "Mid", "Premium Home Office": "Pro",
  "All-Round Family PC": "Mid",
};

const COMPARISON_CAT = {
  "Gaming": "Gaming", "Streaming": "Streaming", "Workstation": "Workstation",
  "Content Creation": "Content Creation", "General / Office": "General / Office",
};

function shortCpu(name) {
  return name
    .replace(/^AMD\s+Ryzen\s+/, "Ryzen ")
    .replace(/^AMD\s+Athlon\s+/, "Athlon ")
    .replace(/^Intel\s+Xeon\s+/, "Xeon ")
    .replace(/^Intel\s+Core\s+/, "Core ");
}

function shortGpu(name) {
  if (!name) return "";
  return name
    .replace(/^NVIDIA\s+GeForce\s+/, "")
    .replace(/^AMD\s+Radeon\s+/, "")
    .replace(/^Intel\s+/, "")
    .replace(/\s+\d+GB\s+GDDR[567]X?$/i, "")
    .replace(/\s+LHR$/i, "")
    .replace(/\s*\(×\d+\)$/i, m => m);
}

function descCpu(name) { return name; }
function descGpu(name) {
  if (!name) return "";
  return name.replace(/\s+LHR$/i, "");
}
function descSsd(name) {
  return name.replace(/\s+NVMe PCIe \d+\.\d$/i, "").replace(/\s+M\.2 SATA$/i, "").replace(/\s+NVMe$/i, "");
}
function descRam(name) { return name; }
function descPsu(name) {
  return name
    .replace(/\s+80\+\s+(Bronze|Gold|Platinum|Titanium)$/i, "")
    .replace(/\s+230V$/i, "")
    .replace(/\s+SFX$/i, "");
}

function generateHighlights(b) {
  const lines = [];
  const tier = TIER_MAP[b.name] || "Mid";
  const isServer = b.name.includes("Server");
  const isWorkstation = b.scenario === "Workstation";
  const isCreator = b.scenario === "Content Creation";

  if (isServer) {
    lines.push("- Enterprise-grade reliability");
    lines.push("- ECC memory for data integrity");
  } else if (isWorkstation) {
    if (tier === "Ultimate" || tier === "High-End") lines.push("- Extreme professional power");
    else if (tier === "Pro") lines.push("- Strong professional performance");
    else lines.push("- Reliable workstation performance");
  } else if (isCreator) {
    if (tier === "Ultimate" || tier === "High-End") lines.push("- Flagship creator performance");
    else if (tier === "Pro") lines.push("- Strong creator performance");
    else lines.push("- Great for content creation");
  } else if (b.scenario === "Streaming") {
    if (tier === "Ultimate" || tier === "High-End") lines.push("- Elite streaming quality");
    else if (tier === "Pro") lines.push("- High-quality streaming");
    else if (tier === "Mid") lines.push("- Solid streaming performance");
    else lines.push("- Entry-level streaming");
  } else if (b.scenario === "General / Office") {
    if (tier === "Pro" || tier === "High-End") lines.push("- Premium productivity power");
    else lines.push("- Great for everyday tasks");
  } else {
    if (tier === "Ultimate" || tier === "High-End") lines.push("- Extreme gaming power");
    else if (tier === "Pro") lines.push("- Strong gaming performance");
    else if (tier === "Mid") lines.push("- Solid gaming performance");
    else lines.push("- Great value gaming");
  }

  if (b.gpu) {
    // GPU line already added above contextually
  } else {
    lines.push("- Integrated graphics");
  }
  lines.push("- Fast NVMe storage");
  if (parseInt(b.ram) >= 128) lines.push("- Massive RAM capacity");
  else if (parseInt(b.ram) >= 64) lines.push("- High RAM capacity");
  lines.push("- Professionally built and tested");
  return lines.join("\\n");
}

function generateSeoKeywords(b) {
  const cpu = shortCpu(b.cpu).toLowerCase();
  const gpu = b.gpu ? shortGpu(b.gpu).replace(/\s*\d+GB\s+GDDR[567]X?/gi, "").trim().toLowerCase() : "";
  const scenario = b.scenario.toLowerCase().replace(" / ", " ");
  const parts = [scenario + " pc", cpu + " build"];
  if (gpu) parts.push(gpu + " build");
  if (b.scenario === "Content Creation") parts.push("creator pc", "video editing desktop");
  if (b.scenario === "General / Office") parts.push("office pc", "home computer", "productivity pc");
  return parts.join(", ");
}

enriched.forEach((b, idx) => {
  const fullName = b.name + " — " + b.scenario + " Build";
  const tier = TIER_MAP[b.name] || "Mid";

  const parts = [
    "CPU: " + descCpu(b.cpu),
    b.gpu ? "GPU: " + descGpu(b.gpu) : null,
    "Motherboard: " + b.mb,
    "RAM: " + descRam(b.ram),
    "SSD: " + descSsd(b.ssd),
    "Case: " + b.case_,
    "CPU Cooler: " + b.cooler,
    "PSU: " + descPsu(b.psu),
  ].filter(Boolean);
  const desc = parts.join(" | ") + " | Professionally built and tested.";

  const seoTitle = (b.name.startsWith("PCTG ") ? "" : "PCTG ") + b.name +
    (b.gpu ? " – " + shortCpu(b.cpu) + " + " + shortGpu(b.gpu) : "");

  const seoDesc = b.name.includes("Server")
    ? "Enterprise " + shortCpu(b.cpu) + " server with " + shortGpu(b.gpu) + ". Built for virtualization, simulation, and data-intensive workloads."
    : "Professional " + b.scenario.toLowerCase().replace(" / ", " ") + " PC with " +
    shortCpu(b.cpu) + (b.gpu ? " and " + shortGpu(b.gpu) : "") +
    ". Built, tested, and delivered with Windows installed.";

  const summary = b.name.includes("Server")
    ? "Enterprise server workstation for virtualization, simulation, and data-intensive workloads."
    : b.gpu
    ? (b.scenario === "Gaming" ? "Gaming PC built for " + tier.toLowerCase() + "-level performance."
      : b.scenario === "Streaming" ? "Streaming PC built for " + tier.toLowerCase() + "-quality content creation."
      : b.scenario === "Workstation" ? "Workstation built for " + tier.toLowerCase() + "-level professional work."
      : "Creator PC built for " + tier.toLowerCase() + "-level production work.")
    : "Office PC built for everyday productivity and multitasking.";

  const highlights = generateHighlights(b);
  const keywords = generateSeoKeywords(b);
  const compCat = COMPARISON_CAT[b.scenario] || b.scenario;

  const sku = "PCTG-HB-" + String(idx + 1).padStart(3, "0");

  const esc = s => csvEscape(String(s ?? ""));

  importRows.push([
    esc(sku),                                           // 1 SKU
    esc(fullName),                                      // 2 Name
    esc(""),                                            // 3 Parent Product SKU
    esc(b.bundled),                                     // 4 Price
    esc(""),                                            // 5 Sale Price
    esc(desc),                                          // 6 Description
    esc("No"),                                          // 7 Track Inventory
    esc(""),                                            // 8 QTY
    esc("No"),                                          // 9 Backorder
    esc(""),                                            // 10 Weight
    esc(""),                                            // 11 Length
    esc(""),                                            // 12 Width
    esc(""),                                            // 13 Height
    esc("Non-Taxable"),                                 // 14 Tax Category
    esc("No"),                                          // 15 Hidden
    esc(b.scenario),                                    // 16 Category
    esc(""),                                            // 17 Image URL
    esc(seoTitle),                                      // 18 SEO Title
    esc(seoDesc),                                       // 19 SEO Desc
    esc(summary),                                       // 20 Summary
    esc(keywords),                                      // 21 SEO Keywords
    esc(highlights),                                    // 22 Feature Highlights
    esc(compCat),                                       // 23 Comparison Category
    esc(tier),                                          // 24 Comparison Tier
    esc("CPU"),                                         // 25 Option1 Name
    esc(b.cpu),                                         // 26 Option1 Values
    esc(b.gpu ? "GPU" : "Motherboard"),                // 27 Option2 Name
    esc(b.gpu || b.mb),                                 // 28 Option2 Values
    esc(b.gpu ? "Motherboard" : "RAM"),                // 29 Option3 Name
    esc(b.gpu ? b.mb : b.ram),                         // 30 Option3 Values
    esc(b.gpu ? "RAM" : "SSD"),                        // 31 Option4 Name
    esc(b.gpu ? b.ram : b.ssd),                        // 32 Option4 Values
    esc(b.gpu ? "SSD" : "Case"),                       // 33 Option5 Name
    esc(b.gpu ? b.ssd : b.case_),                      // 34 Option5 Values
    esc(""),                                            // 35 Add-on1 Name
    esc(""),                                            // 36 Add-on1 Type
    esc(""),                                            // 37 Add-on1 Required
    esc(""),                                            // 38 Add-on1 Values
    esc(""),                                            // 39 Add-on2 Name
    esc(""),                                            // 40 Add-on2 Type
    esc(""),                                            // 41 Add-on2 Required
    esc(""),                                            // 42 Add-on2 Values
  ].join(","));
});

const importHeader = "SKU,Name,Parent Product SKU,Price,Sale Price,Description,Track Inventory,QTY,Backorder,Weight,Length,Width,Height,Tax Category,Hidden,Category,Image URL,SEO Title,SEO Desc,Summary,SEO Keywords,Feature Highlights,Comparison Category,Comparison Tier,Option1 Name,Option1 Values,Option2 Name,Option2 Values,Option3 Name,Option3 Values,Option4 Name,Option4 Values,Option5 Name,Option5 Values,Add-on1 Name,Add-on1 Type,Add-on1 Required,Add-on1 Values,Add-on2 Name,Add-on2 Type,Add-on2 Required,Add-on2 Values";

const importCsv = importHeader + "\n" + importRows.join("\n") + "\n";
fs.writeFileSync(path.join(ROOT, "hero-builds-import.csv"), importCsv, "utf-8");
console.log("hero-builds-import.csv written (" + importRows.length + " rows, " + importHeader.split(",").length + " columns)");

// ── Verify column counts ──────────────────────────────────────────
importRows.forEach((row, i) => {
  const count = parseCSVLine(row).length;
  if (count !== 42) console.error("  ROW " + (i+1) + " has " + count + " columns (expected 42)");
});

console.log("\nDone. Sample bundled prices:");
enriched.slice(0, 5).forEach(b => {
  console.log("  " + b.name + ": £" + b.bundled + " (components: £" + b.componentTotal.toFixed(2) + ")");
});
