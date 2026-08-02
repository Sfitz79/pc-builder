const fs = require("fs");
const path = require("path");

const DATA_DIR = path.join(__dirname, "..", "src", "data");

const FIELD_REMAP = {
  performance_core_clock: "core_clock",
  performance_core_boost_clock: "boost_clock",
  integrated_graphics: "graphics",
  "response_time_(g2g)": "response_time",
  socket_cpu: "socket",
};

const SKIP_COLS = new Set([
  "web_scraper_order", "web_scraper_start_url", "pagination",
]);

const DEDUP_RE = /^(.+)\d+$/;

function stripPrefix(header, value) {
  if (!value || typeof value !== "string") return value;
  const prefix = header.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase());
  const normPrefix = prefix.toLowerCase().replace(/[^a-z0-9]/g, "").replace(/\s+/g, "");
  const normValue = value.toLowerCase().replace(/[^a-z0-9]/g, "").replace(/\s+/g, "");
  let result = value;
  if (result.length > prefix.length && normValue.startsWith(normPrefix)) {
    let matched = 0;
    let prefixLen = 0;
    for (let i = 0; i < result.length && matched < normPrefix.length; i++) {
      const ch = result[i].toLowerCase().replace(/[^a-z0-9]/g, "");
      if (ch) matched++;
      if (matched <= normPrefix.length) prefixLen = i + 1;
    }
    result = result.slice(prefixLen);
  }
  return result.trim();
}

function extractPrice(value) {
  if (!value || typeof value !== "string") return "";
  const m = value.match(/[\d,.]+/);
  return m ? m[0].replace(/,/g, "") : "";
}

function normalizeValue(value) {
  const trimmed = (value ?? "").trim();
  if (trimmed === "") return "";
  const numeric = Number(trimmed);
  return Number.isNaN(numeric) ? trimmed : numeric;
}

function parseCSVLine(line) {
  const values = [];
  let current = "";
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    const next = line[i + 1];
    if (ch === '"') {
      if (inQuotes && next === '"') { current += '"'; i++; }
      else inQuotes = !inQuotes;
      continue;
    }
    if (ch === "," && !inQuotes) { values.push(current); current = ""; continue; }
    current += ch;
  }
  values.push(current);
  return values;
}

function loadCSV(file) {
  const text = fs.readFileSync(path.join(DATA_DIR, file), "utf-8");
  const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  if (lines.length < 2) return [];

  const headers = parseCSVLine(lines[0]);
  const rows = lines.slice(1);

  const isRaw = headers.includes("web_scraper_order");
  if (!isRaw) return parseCleanCSV(headers, rows);
  return parseRawCSV(headers, rows);
}

function parseCleanCSV(headers, rows) {
  return rows.map(row => {
    const vals = parseCSVLine(row);
    const item = {};
    for (let i = 0; i < headers.length; i++) {
      item[headers[i]] = normalizeValue(vals[i] ?? "");
    }
    return item;
  }).filter(item => item.name);
}

function parseRawCSV(headers, rows) {
  const firstVals = rows[0] ? parseCSVLine(rows[0]) : [];
  let priceCol = -1;
  for (const c of ["price", "price2"]) {
    const idx = headers.indexOf(c);
    if (idx !== -1) { priceCol = idx; break; }
  }
  let imageCol = -1;
  for (const c of ["name2", "name4"]) {
    const idx = headers.indexOf(c);
    if (idx !== -1 && (firstVals[idx] ?? "").includes("http")) { imageCol = idx; break; }
  }
  const ratingCol = headers.indexOf("rating");

  return rows.map(row => {
    const vals = parseCSVLine(row);
    if (!vals[0]?.trim()) return null;
    const item = {};
    for (let i = 0; i < headers.length; i++) {
      const h = headers[i];
      if (SKIP_COLS.has(h)) continue;
      if (DEDUP_RE.test(h) && h !== "name2" && h !== "name4" && h !== "price2" && h !== "price3" && h !== "price4") {
        const base = h.match(DEDUP_RE)[1];
        if (headers.includes(base) || headers.indexOf(h) > headers.indexOf(base)) continue;
      }
      const raw = (vals[i] ?? "").trim();
      if (!raw) continue;
      if (h === "name") { item.name = raw; continue; }
      if (i === ratingCol) { const m = raw.match(/\((\d+)\)/); if (m) item.rating = normalizeValue(m[1]); continue; }
      if (i === imageCol && raw.startsWith("http")) { item.image = raw; continue; }
      if (h === "price" || h === "price2" || h === "price3" || h === "price4") {
        if (i === priceCol) { const p = extractPrice(raw); if (p) item.price = normalizeValue(p); }
        continue;
      }
      if (["name2", "name3", "name4"].includes(h)) continue;
      const mapped = FIELD_REMAP[h] || h;
      const cleaned = stripPrefix(h, raw);
      if (cleaned) item[mapped] = normalizeValue(cleaned);
    }
    if (!item.name?.trim()) return null;
    return item;
  }).filter(Boolean);
}

function num(v) { return parseFloat(v) || 0; }

function parsePrice(p) { return num(String(p).replace(/[^0-9.]/g, "")); }

function parseSpeed(s) {
  const m = String(s).match(/(\d+)/);
  return m ? parseInt(m[1]) : 0;
}

function getRamDdr(ram) {
  const s = String(ram.speed || ram.ram_type || "").toUpperCase();
  if (s.includes("DDR5")) return "DDR5";
  if (s.includes("DDR4")) return "DDR4";
  return "";
}

function inferCpuSocket(cpu) {
  if (cpu.socket) return String(cpu.socket).trim();
  const name = String(cpu.name || "").toLowerCase();
  const arch = String(cpu.microarchitecture || "").toLowerCase();
  if (arch.includes("zen 5") || arch.includes("zen 4") || name.includes("9850x3d") || name.includes("9800x3d") || name.includes("9700x") || name.includes("9600x") || name.includes("ryzen 9 9") || name.includes("ryzen 7 9") || name.includes("ryzen 5 9") || name.includes("7800x3d") || name.includes("7700x") || name.includes("7600x") || name.includes("7500f")) return "AM5";
  if (arch.includes("zen 3") || arch.includes("zen 2") || name.includes("5800x3d") || name.includes("5700x") || name.includes("5600x") || name.includes("5500")) return "AM4";
  if (name.includes("14900") || name.includes("14700") || name.includes("14600") || name.includes("13900") || name.includes("13700") || name.includes("13600") || name.includes("12900") || name.includes("12700") || name.includes("12600")) return "LGA1700";
  if (name.includes("ultra 9") || name.includes("ultra 7") || name.includes("ultra 5") || name.includes("285k") || name.includes("265k") || name.includes("245k")) return "LGA1851";
  return "";
}

function isModernGpu(gpu) {
  const search = (String(gpu.name ?? "") + " " + String(gpu.chipset ?? "")).toUpperCase();
  const memory = num(gpu.memory);
  if (memory > 0 && memory < 6) return false;
  if (search.includes("GT ") || search.includes("GTS ") || search.includes("GEFORCE GT")) return false;
  if (search.includes("GTX")) return false;
  const rtxMatch = search.match(/RTX\s*(\d{4})/);
  if (rtxMatch) return parseInt(rtxMatch[1]) >= 3060;
  const rxMatch = search.match(/RX\s*(\d{4,5})/);
  if (rxMatch) return parseInt(rxMatch[1]) >= 6000;
  if (search.includes("ARC B")) return true;
  const arcMatch = search.match(/ARC\s*A(\d{3,4})/);
  if (arcMatch) return parseInt(arcMatch[1]) >= 750;
  return false;
}

function isModernCpu(cpu) {
  const socket = inferCpuSocket(cpu);
  const validSockets = new Set(["AM4", "AM5", "LGA1700", "LGA1851"]);
  if (socket && !validSockets.has(socket)) return false;
  return true;
}

function isModernMb(mb) {
  const socket = String(mb.socket || "").trim();
  const validSockets = new Set(["AM4", "AM5", "LGA1700", "LGA1851"]);
  if (socket && !validSockets.has(socket)) return false;
  return true;
}

function isModernRam(ram) {
  const ddr = getRamDdr(ram);
  if (ddr === "DDR4" && parseSpeed(ram.speed) <= 3000) return false;
  if (ddr !== "DDR4" && ddr !== "DDR5") return false;
  return true;
}

function isModernPsu(psu) { return num(psu.wattage) >= 550; }

function getGpuTier(gpu) {
  const s = (String(gpu.name ?? "") + " " + String(gpu.chipset ?? "")).toUpperCase();
  const mem = num(gpu.memory);
  if (s.includes("RTX 5090")) return 10;
  if (s.includes("RTX 5080")) return 9;
  if (s.includes("RTX 4090")) return 9;
  if (s.includes("RTX 5070 TI") || s.includes("RTX 4080")) return 8;
  if (s.includes("RTX 5070") || s.includes("RTX 4070 TI")) return 7.5;
  if (s.includes("RTX 4070")) return 7;
  if (s.includes("RX 9070 XT") || s.includes("RX 7800 XT")) return 7;
  if (s.includes("RX 9070") || s.includes("RX 7700 XT")) return 6.5;
  if (s.includes("RTX 4060 TI")) return 6;
  if (s.includes("RX 7600 XT") || s.includes("RTX 4060")) return 5.5;
  if (s.includes("RX 7600")) return 5;
  if (s.includes("RX 6700 XT") || s.includes("RX 6750")) return 5;
  if (mem >= 12) return 6;
  if (mem >= 8) return 4;
  return 3;
}

function getCpuTier(cpu) {
  const name = String(cpu.name || "").toLowerCase();
  const cores = num(cpu.core_count);
  if (name.includes("9950x3d") || name.includes("9950x") || name.includes("14900") || name.includes("13900") || name.includes("ultra 9")) return 10;
  if (name.includes("9850x3d") || name.includes("9800x3d") || name.includes("14700") || name.includes("13700") || name.includes("ultra 7")) return 8;
  if (name.includes("9700x") || name.includes("7800x3d") || name.includes("14600") || name.includes("13600") || name.includes("ultra 5")) return 7;
  if (name.includes("9600x") || name.includes("7700x") || name.includes("7600x")) return 6;
  if (name.includes("7500f") || name.includes("5800x3d") || name.includes("5700x")) return 5.5;
  if (name.includes("5600x") || name.includes("5500")) return 4.5;
  if (cores >= 8) return 6;
  if (cores >= 6) return 5;
  return 3;
}

function getCpuSocketPairings() {
  return {
    "AM5": { cpus: [], motherboards: [], rams: [] },
    "AM4": { cpus: [], motherboards: [], rams: [] },
    "LGA1700": { cpus: [], motherboards: [], rams: [] },
    "LGA1851": { cpus: [], motherboards: [], rams: [] },
  };
}

function pick(items, fn) {
  const matches = items.filter(fn);
  return matches.length > 0 ? matches[0] : null;
}

function pickBest(items, scoreFn) {
  if (items.length === 0) return null;
  return items.reduce((best, item) => scoreFn(item) > scoreFn(best) ? item : best);
}

function priceRange(min, max) {
  return (item) => {
    const p = parsePrice(item.price);
    return p >= min && p <= max;
  };
}

function escapeCsv(val) {
  const s = String(val ?? "");
  if (s.includes(",") || s.includes('"') || s.includes("\n")) {
    return '"' + s.replace(/"/g, '""') + '"';
  }
  return s;
}

const BUILD_SERVICE = 150;
const OS_PRICE = 35;
const DELIVERY_PRICE = 50;
const SURCHARGE_RATE = 0.03;

function calcBundledPrice(componentsTotal) {
  const mandatory = BUILD_SERVICE + OS_PRICE + DELIVERY_PRICE;
  const subtotal = componentsTotal + mandatory;
  return Math.ceil(subtotal * (1 + SURCHARGE_RATE));
}

function buildRow(name, scenario, tier, selections) {
  const cols = [
    "build_name", "scenario", "price_tier",
    "cpu_name", "cpu_price",
    "gpu_name", "gpu_price",
    "motherboard_name", "motherboard_price",
    "ram_name", "ram_price",
    "ssd_name", "ssd_price",
    "case_name", "case_price",
    "cooler_name", "cooler_price",
    "psu_name", "psu_price",
    "monitor_name", "monitor_price",
    "components_total", "build_service", "os_fee", "delivery_fee", "surcharge", "total_price",
  ];
  const row = {};
  row.build_name = name;
  row.scenario = scenario;
  row.price_tier = tier;

  const cats = {
    cpu: selections.cpu,
    gpu: selections.gpu,
    motherboard: selections.motherboard,
    ram: selections.ram,
    ssd: selections.ssd,
    case: selections.case,
    cooler: selections.cooler,
    psu: selections.psu,
    monitor: selections.monitor,
  };

  let componentsTotal = 0;
  for (const [cat, item] of Object.entries(cats)) {
    if (item) {
      row[cat + "_name"] = item.name;
      row[cat + "_price"] = parsePrice(item.price).toFixed(2);
      componentsTotal += parsePrice(item.price);
    } else {
      row[cat + "_name"] = "";
      row[cat + "_price"] = "";
    }
  }

  row.components_total = componentsTotal.toFixed(2);
  row.build_service = BUILD_SERVICE.toFixed(2);
  row.os_fee = OS_PRICE.toFixed(2);
  row.delivery_fee = DELIVERY_PRICE.toFixed(2);
  const mandatory = BUILD_SERVICE + OS_PRICE + DELIVERY_PRICE;
  const subtotal = componentsTotal + mandatory;
  row.surcharge = (subtotal * SURCHARGE_RATE).toFixed(2);
  row.total_price = calcBundledPrice(componentsTotal).toFixed(2);
  return row;
}

async function main() {
  console.log("Loading component data...");
  const cpus = loadCSV("cpu.csv").filter(isModernCpu);
  const gpus = loadCSV("gpu.csv").filter(isModernGpu);
  const motherboards = loadCSV("motherboard.csv").filter(isModernMb);
  const rams = loadCSV("ram.csv").filter(isModernRam);
  const ssds = loadCSV("ssd.csv").filter(s => s.name && parsePrice(s.price) > 0);
  const cases = loadCSV("case.csv").filter(c => c.name && parsePrice(c.price) > 0);
  const coolers = loadCSV("cooler.csv").filter(c => c.name && parsePrice(c.price) > 0);
  const psus = loadCSV("power-supply.csv").filter(isModernPsu);
  const monitors = loadCSV("monitor.csv").filter(m => m.name && parsePrice(m.price) > 0);

  console.log(`CPUs: ${cpus.length}, GPUs: ${gpus.length}, Mobos: ${motherboards.length}, RAM: ${rams.length}`);
  console.log(`SSDs: ${ssds.length}, Cases: ${cases.length}, Coolers: ${coolers.length}, PSUs: ${psus.length}, Monitors: ${monitors.length}`);

  // Sort by price for easy selection
  cpus.sort((a, b) => parsePrice(a.price) - parsePrice(b.price));
  gpus.sort((a, b) => parsePrice(a.price) - parsePrice(b.price));
  motherboards.sort((a, b) => parsePrice(a.price) - parsePrice(b.price));
  rams.sort((a, b) => parsePrice(a.price) - parsePrice(b.price));
  ssds.sort((a, b) => parsePrice(a.price) - parsePrice(b.price));
  cases.sort((a, b) => parsePrice(a.price) - parsePrice(b.price));
  coolers.sort((a, b) => parsePrice(a.price) - parsePrice(b.price));
  psus.sort((a, b) => parsePrice(a.price) - parsePrice(b.price));
  monitors.sort((a, b) => parsePrice(a.price) - parsePrice(b.price));

  // Group CPUs by socket
  const socketCpus = {};
  for (const cpu of cpus) {
    const s = inferCpuSocket(cpu);
    if (!socketCpus[s]) socketCpus[s] = [];
    socketCpus[s].push(cpu);
  }

  // Group motherboards by socket
  const socketMbos = {};
  for (const mb of motherboards) {
    const s = String(mb.socket || "").trim();
    if (!socketMbos[s]) socketMbos[s] = [];
    socketMbos[s].push(mb);
  }

  // Group RAM by DDR type
  const ddr4Rams = rams.filter(r => getRamDdr(r) === "DDR4");
  const ddr5Rams = rams.filter(r => getRamDdr(r) === "DDR5");

  function pickRamForSocket(socket) {
    if (socket === "AM5" || socket === "LGA1851") return ddr5Rams;
    return ddr4Rams;
  }

  function selectBuild(config) {
    const { cpu, gpuBudget, ramCapacity, ssdCapacity, psuMin, monitorHz, monitorRes, coolerMax, budget, skipGpu } = config;

    const socket = inferCpuSocket(cpu);
    const mbPool = (socketMbos[socket] || []).filter(priceRange(40, 600));
    const mb = pickBest(mbPool, m => -Math.abs(parsePrice(m.price) - (budget * 0.10)));
    if (!mb) return null;

    const ramPool = pickRamForSocket(socket).filter(r => {
      const cap = String(r.modules || r.name || "").match(/(\d+)\s*GB/);
      const capNum = cap ? parseInt(cap[1]) : 0;
      return capNum >= ramCapacity;
    });
    const ram = pickBest(ramPool, r => -Math.abs(parsePrice(r.price) - (budget * 0.06)));
    if (!ram) return null;

    let gpu = null;
    if (!skipGpu && gpuBudget > 0) {
      const gpuMin = Math.max(50, gpuBudget * 0.4);
      const gpuMax = gpuBudget * 2;
      const gpuPool = gpus.filter(priceRange(gpuMin, gpuMax));
      gpu = pickBest(gpuPool, g => -Math.abs(parsePrice(g.price) - gpuBudget));
      if (!gpu) {
        const gpuPool2 = gpus.filter(priceRange(100, gpuBudget * 2.5));
        gpu = pickBest(gpuPool2, g => -Math.abs(parsePrice(g.price) - gpuBudget));
      }
    }

    const ssdPool = ssds.filter(s => {
      const cap = String(s.capacity || s.name || "").match(/(\d+)\s*(TB|GB)/i);
      if (!cap) return false;
      const capVal = cap[2].toUpperCase() === "TB" ? parseInt(cap[1]) * 1000 : parseInt(cap[1]);
      return capVal >= ssdCapacity && parsePrice(s.price) > 10;
    });
    const ssd = pickBest(ssdPool, s => -Math.abs(parsePrice(s.price) - (budget * 0.06)));
    if (!ssd) return null;

    const casePool = cases.filter(priceRange(30, 350));
    const caseItem = pickBest(casePool, c => -Math.abs(parsePrice(c.price) - (budget * 0.05)));
    if (!caseItem) return null;

    const coolerPool = coolers.filter(c => {
      if (coolerMax && parsePrice(c.price) > coolerMax) return false;
      if (num(cpu.tdp) > 150) {
        const rs = String(c.radiator_size || "").match(/(\d+)/);
        return !rs || parseInt(rs[1]) >= 240;
      }
      return true;
    });
    const cooler = pickBest(coolerPool, c => -Math.abs(parsePrice(c.price) - Math.min(budget * 0.04, 150)));
    if (!cooler) return null;

    const gpuPower = gpu ? num(gpu.tdp) || 200 : 50;
    const cpuPower = num(cpu.tdp) || 125;
    const psuNeeded = Math.max(psuMin, cpuPower + gpuPower + 150);
    const psuPool = psus.filter(p => num(p.wattage) >= psuNeeded && parsePrice(p.price) > 30);
    const psu = pickBest(psuPool, p => -Math.abs(parsePrice(p.price) - (budget * 0.05)));
    if (!psu) return null;

    let monitor = null;
    if (monitorRes && monitorHz) {
      const monPool = monitors.filter(m => {
        const res = String(m.resolution || "").toLowerCase();
        const hz = num(m.refresh_rate);
        const resMatch = monitorRes === "1080p" ? res.includes("1920") :
          monitorRes === "1440p" ? res.includes("2560") :
          monitorRes === "4k" ? (res.includes("3840") || res.includes("2160")) : true;
        return resMatch && hz >= monitorHz - 20;
      });
      monitor = pickBest(monPool, m => -Math.abs(parsePrice(m.price) - (budget * 0.10)));
    }

    return { cpu, gpu, motherboard: mb, ram, ssd, case: caseItem, cooler, psu, monitor };
  }

  // ===== SCENARIOS =====
  const scenarios = [
    {
      name: "Gaming",
      builds: [
        {
          label: "Budget Gaming",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) <= 6 && parsePrice(c.price) < 200 ? 1 : 0) || pickBest(socketCpus["AM4"] || [], c => parsePrice(c.price) < 150 ? 1 : 0), gpuBudget: 200, ramCapacity: 16, ssdCapacity: 500, psuMin: 550, monitorHz: 144, monitorRes: "1080p", coolerMax: 50, budget: 700 },
        },
        {
          label: "1080p Gaming",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 150 && parsePrice(c.price) <= 300 ? 1 : 0), gpuBudget: 300, ramCapacity: 32, ssdCapacity: 1000, psuMin: 650, monitorHz: 165, monitorRes: "1080p", coolerMax: 80, budget: 1100 },
        },
        {
          label: "1440p Gaming",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 300 && parsePrice(c.price) <= 500 ? 1 : 0), gpuBudget: 500, ramCapacity: 32, ssdCapacity: 1000, psuMin: 750, monitorHz: 165, monitorRes: "1440p", coolerMax: 120, budget: 1800 },
        },
        {
          label: "4K Gaming",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 400 && parsePrice(c.price) <= 600 ? 1 : 0), gpuBudget: 800, ramCapacity: 32, ssdCapacity: 2000, psuMin: 850, monitorHz: 144, monitorRes: "4k", coolerMax: 150, budget: 2800 },
        },
        {
          label: "Ultimate Gaming",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 500 ? 1 : 0), gpuBudget: 1500, ramCapacity: 64, ssdCapacity: 2000, psuMin: 1000, monitorHz: 144, monitorRes: "4k", coolerMax: 200, budget: 4500 },
        },
      ],
    },
    {
      name: "Streaming",
      builds: [
        {
          label: "Budget Streamer",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) >= 6 && parsePrice(c.price) <= 200 ? 1 : 0), gpuBudget: 250, ramCapacity: 32, ssdCapacity: 1000, psuMin: 650, monitorHz: 144, monitorRes: "1080p", coolerMax: 60, budget: 900 },
        },
        {
          label: "1080p Streamer",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) >= 8 && parsePrice(c.price) >= 250 && parsePrice(c.price) <= 400 ? 1 : 0), gpuBudget: 400, ramCapacity: 32, ssdCapacity: 1000, psuMin: 750, monitorHz: 165, monitorRes: "1080p", coolerMax: 100, budget: 1400 },
        },
        {
          label: "1440p Streamer",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) >= 8 && parsePrice(c.price) >= 350 ? 1 : 0), gpuBudget: 600, ramCapacity: 32, ssdCapacity: 2000, psuMin: 850, monitorHz: 165, monitorRes: "1440p", coolerMax: 150, budget: 2200 },
        },
        {
          label: "Pro Streamer",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 400 ? 1 : 0), gpuBudget: 1000, ramCapacity: 32, ssdCapacity: 2000, psuMin: 850, monitorHz: 144, monitorRes: "4k", coolerMax: 250, budget: 3200 },
        },
        {
          label: "Ultimate Streamer",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 450 ? 1 : 0), gpuBudget: 1500, ramCapacity: 32, ssdCapacity: 2000, psuMin: 1000, monitorHz: 144, monitorRes: "4k", coolerMax: 350, budget: 5000 },
        },
      ],
    },
    {
      name: "Workstation",
      builds: [
        {
          label: "Budget Workstation",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) >= 6 && parsePrice(c.price) <= 250 ? 1 : 0), gpuBudget: 200, ramCapacity: 32, ssdCapacity: 1000, psuMin: 550, monitorHz: 60, monitorRes: "1440p", coolerMax: 60, budget: 850 },
        },
        {
          label: "Mid Workstation",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) >= 8 && parsePrice(c.price) >= 250 && parsePrice(c.price) <= 450 ? 1 : 0), gpuBudget: 400, ramCapacity: 64, ssdCapacity: 1000, psuMin: 750, monitorHz: 60, monitorRes: "1440p", coolerMax: 100, budget: 1600 },
        },
        {
          label: "Pro Workstation",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) >= 8 && parsePrice(c.price) >= 400 ? 1 : 0), gpuBudget: 700, ramCapacity: 64, ssdCapacity: 2000, psuMin: 850, monitorHz: 60, monitorRes: "4k", coolerMax: 150, budget: 2500 },
        },
        {
          label: "High-End Workstation",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 400 ? 1 : 0), gpuBudget: 1200, ramCapacity: 64, ssdCapacity: 2000, psuMin: 850, monitorHz: 60, monitorRes: "4k", coolerMax: 250, budget: 3500 },
        },
        {
          label: "Ultimate Workstation",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 450 ? 1 : 0), gpuBudget: 1800, ramCapacity: 64, ssdCapacity: 4000, psuMin: 1000, monitorHz: 60, monitorRes: "4k", coolerMax: 350, budget: 5500 },
        },
      ],
    },
    {
      name: "Content Creation",
      builds: [
        {
          label: "Budget Creator",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) >= 6 && parsePrice(c.price) <= 200 ? 1 : 0), gpuBudget: 200, ramCapacity: 32, ssdCapacity: 1000, psuMin: 550, monitorHz: 60, monitorRes: "1080p", coolerMax: 50, budget: 750 },
        },
        {
          label: "YouTube Creator",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) >= 8 && parsePrice(c.price) >= 250 && parsePrice(c.price) <= 400 ? 1 : 0), gpuBudget: 400, ramCapacity: 64, ssdCapacity: 2000, psuMin: 750, monitorHz: 60, monitorRes: "1440p", coolerMax: 100, budget: 1500 },
        },
        {
          label: "Pro Video Editor",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => num(c.core_count) >= 8 && parsePrice(c.price) >= 350 ? 1 : 0), gpuBudget: 700, ramCapacity: 64, ssdCapacity: 4000, psuMin: 850, monitorHz: 60, monitorRes: "4k", coolerMax: 150, budget: 2500 },
        },
        {
          label: "Studio Workstation",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 400 ? 1 : 0), gpuBudget: 1200, ramCapacity: 64, ssdCapacity: 4000, psuMin: 850, monitorHz: 60, monitorRes: "4k", coolerMax: 250, budget: 3800 },
        },
        {
          label: "Ultimate Creator",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 450 ? 1 : 0), gpuBudget: 2000, ramCapacity: 64, ssdCapacity: 4000, psuMin: 1000, monitorHz: 60, monitorRes: "4k", coolerMax: 350, budget: 6000 },
        },
      ],
    },
    {
      name: "General / Office",
      builds: [
        {
          label: "Basic Home PC",
          config: { cpu: pickBest(socketCpus["AM5"] || socketCpus["AM4"] || [], c => parsePrice(c.price) <= 130 ? 1 : 0), gpuBudget: 0, ramCapacity: 16, ssdCapacity: 500, psuMin: 450, monitorHz: 60, monitorRes: "1080p", coolerMax: 30, budget: 400, skipGpu: true },
        },
        {
          label: "Office Productivity",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 100 && parsePrice(c.price) <= 200 ? 1 : 0), gpuBudget: 0, ramCapacity: 16, ssdCapacity: 500, psuMin: 450, monitorHz: 75, monitorRes: "1080p", coolerMax: 40, budget: 550, skipGpu: true },
        },
        {
          label: "Home Office Pro",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 150 && parsePrice(c.price) <= 250 ? 1 : 0), gpuBudget: 0, ramCapacity: 32, ssdCapacity: 1000, psuMin: 550, monitorHz: 100, monitorRes: "1080p", coolerMax: 60, budget: 750, skipGpu: true },
        },
        {
          label: "Premium Home Office",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 200 && parsePrice(c.price) <= 350 ? 1 : 0), gpuBudget: 0, ramCapacity: 32, ssdCapacity: 1000, psuMin: 550, monitorHz: 100, monitorRes: "1440p", coolerMax: 80, budget: 1000, skipGpu: true },
        },
        {
          label: "All-Round Family PC",
          config: { cpu: pickBest(socketCpus["AM5"] || [], c => parsePrice(c.price) >= 250 && parsePrice(c.price) <= 400 ? 1 : 0), gpuBudget: 150, ramCapacity: 32, ssdCapacity: 1000, psuMin: 550, monitorHz: 100, monitorRes: "1440p", coolerMax: 80, budget: 1200 },
        },
      ],
    },
  ];

  const allBuilds = [];

  for (const scenario of scenarios) {
    console.log(`\n=== Generating ${scenario.name} builds ===`);
    for (const build of scenario.builds) {
      // Retry with fallback if first pick fails
      let selections = selectBuild(build.config);
      if (!selections) {
        // Fallback: relax constraints
        const relaxedConfig = { ...build.config, gpuBudget: build.config.gpuBudget * 0.7, coolerMax: 999 };
        selections = selectBuild(relaxedConfig);
      }
      if (!selections) {
        console.log(`  SKIP: ${build.label} — no valid combination found`);
        continue;
      }
      const row = buildRow(build.label, scenario.name, build.label, selections);
      allBuilds.push(row);

      const total = row.total_price;
      const compTotal = row.components_total;
      const cpuName = row.cpu_name || "N/A";
      const gpuName = row.gpu_name || "iGPU";
      console.log(`  ${build.label}: £${compTotal} components → £${total} bundled — ${cpuName} + ${gpuName}`);
    }
  }

  // Write CSV
  const headers = [
    "build_name", "scenario", "price_tier",
    "cpu_name", "cpu_price",
    "gpu_name", "gpu_price",
    "motherboard_name", "motherboard_price",
    "ram_name", "ram_price",
    "ssd_name", "ssd_price",
    "case_name", "case_price",
    "cooler_name", "cooler_price",
    "psu_name", "psu_price",
    "monitor_name", "monitor_price",
    "components_total", "build_service", "os_fee", "delivery_fee", "surcharge", "total_price",
  ];

  const csvLines = [headers.join(",")];
  for (const row of allBuilds) {
    csvLines.push(headers.map(h => escapeCsv(row[h])).join(","));
  }

  const outPath = path.join(__dirname, "..", "hero-builds.csv");
  fs.writeFileSync(outPath, csvLines.join("\n"), "utf-8");
  console.log(`\n✅ Generated ${allBuilds.length} hero builds → ${outPath}`);
}

main().catch(err => { console.error(err); process.exit(1); });
