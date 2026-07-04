export function toNumber(value) {
  if (typeof value === "number") {
    return value;
  }
  if (typeof value !== "string") {
    return 0;
  }

  const normalized = value.replace(/,/g, "");
  const match = normalized.match(/-?\d+(\.\d+)?/);
  return match ? Number(match[0]) : 0;
}

export function normalizeToken(value) {
  const token = String(value ?? "").trim().toUpperCase();
  return token || null;
}

export function inferCpuSocket(cpu) {
  const direct = normalizeToken(cpu?.socket);
  if (direct) {
    return direct;
  }

  const microarchitecture = String(cpu?.microarchitecture ?? "").toLowerCase();
  const name = String(cpu?.name ?? "").toLowerCase();

  // AMD Logic
  if (name.includes("ryzen") || name.includes("athlon")) {
    if (microarchitecture.includes("zen 4") || microarchitecture.includes("zen 5")) {
      return "AM5";
    }
    if (
      microarchitecture.includes("zen 3") ||
      microarchitecture.includes("zen 2") ||
      microarchitecture.includes("zen+") ||
      microarchitecture.includes("zen")
    ) {
      return "AM4";
    }
  }

  // Intel Logic
  if (name.includes("intel") || name.includes("core") || name.includes("xeon") || name.includes("pentium") || name.includes("celeron")) {
    if (microarchitecture.includes("arrow lake")) return "LGA1851";
    if (microarchitecture.includes("raptor lake") || microarchitecture.includes("alder lake")) return "LGA1700";
    if (microarchitecture.includes("coffee lake") || microarchitecture.includes("kaby lake") || microarchitecture.includes("skylake")) return "LGA1151";
    if (microarchitecture.includes("comet lake") || microarchitecture.includes("rocket lake")) return "LGA1200";
    if (microarchitecture.includes("broadwell") || microarchitecture.includes("haswell")) return "LGA1150";
    if (microarchitecture.includes("ivy bridge") || microarchitecture.includes("sandy bridge")) return "LGA1155";
    if (microarchitecture.includes("nehalem") || microarchitecture.includes("westmere")) return "LGA1366";
    if (microarchitecture.includes("wolfdale") || microarchitecture.includes("yorkfield") || microarchitecture.includes("core 2") || microarchitecture.includes("wolfdale")) return "LGA775";
    if (microarchitecture === "core") return "LGA775";
    
    // Fallback patterns for Intel
    if (name.includes("lga 1700") || name.includes("lga1700")) return "LGA1700";
    if (name.includes("lga 1151") || name.includes("lga1151")) return "LGA1151";
    if (name.includes("lga 1150") || name.includes("lga1150")) return "LGA1150";
    if (name.includes("lga 1155") || name.includes("lga 1155")) return "LGA1155";
  }

  return null;
}

export function inferChipset(name) {
  if (!name) return null;
  const n = String(name).toUpperCase();
  // Match common patterns like B650, X870, Z790, H770, etc.
  const match = n.match(/\b([ABHXZ][0-9]{3}[E]?)\b/);
  return match ? match[1] : null;
}

export function inferCoolerType(cooler) {
  const name = String(cooler?.name ?? "").toLowerCase();
  // Commonly known AIO brands and keywords
  if (
    name.includes("liquid") || 
    name.includes("aio") || 
    name.includes("water") ||
    name.includes("kraken") ||
    name.includes("elite v3") ||
    name.includes("nautilus") ||
    name.includes("masterliquid") ||
    name.includes("hydro") ||
    name.includes("floe") ||
    name.includes("castle") ||
    name.includes("galahad") ||
    name.includes("pure loop") ||
    name.includes("silent loop") ||
    name.includes("frozen notte") ||
    name.includes("frozen prism") ||
    name.includes("ryujin") ||
    name.includes("ryuo") ||
    name.includes("coreliquid") ||
    name.includes("hydroshift") ||
    name.includes("aqua elite")
  ) {
    return "AIO Liquid Cooler";
  }
  return "Air Cooler";
}

export function isRGB(item) {
  if (!item) return false;
  const searchString = JSON.stringify(item).toLowerCase();
  return searchString.includes("rgb") || searchString.includes("argb");
}

export function getBrand(item) {
  if (item?.brand) return item.brand.toUpperCase();
  if (item?.manufacturer) return item.manufacturer.toUpperCase();
  
  const name = String(item?.name ?? "").trim();
  if (!name) return "UNKNOWN";
  
  return name.split(" ")[0].toUpperCase();
}

const BRAND_DOMAINS = {
  intel: "intel.com",
  amd: "amd.com",
  nvidia: "nvidia.com",
  asus: "asus.com",
  msi: "msi.com",
  gigabyte: "gigabyte.com",
  corsair: "corsair.com",
  evga: "evga.com",
  samsung: "samsung.com",
  kingston: "kingston.com",
  wd: "wd.com",
  seagate: "seagate.com",
  crucial: "crucial.com",
  noctua: "noctua.at",
  lian: "lian-li.com",
  nzxt: "nzxt.com",
  phanteks: "phanteks.com",
  fractal: "fractal-design.com",
  cooler: "coolermaster.com",
  thermaltake: "thermaltake.com",
  bequiet: "bequiet.com",
  asrock: "asrock.com",
  pny: "pny.com",
  zotac: "zotac.com",
  sapphire: "sapphiretech.com",
  powercolor: "powercolor.com",
  xfx: "xfxforce.com",
  gskill: "gskill.com",
  team: "teamgroupinc.com",
  adata: "adata.com",
  lexar: "lexar.com",
  sabrent: "sabrent.com",
  deepcool: "deepcool.com",
  arctic: "arctic.de",
  scythe: "scythe-eu.com",
  ekwb: "ekwb.com",
  silverstone: "silverstonetek.com",
  antec: "antec.com",
  inwin: "in-win.com",
  razer: "razer.com",
  logitech: "logitech.com",
  steelseries: "steelseries.com",
  hyperx: "hyperxgaming.com",
  li: "lian-li.com",
  lianli: "lian-li.com",
  western: "wd.com",
  thermalright: "thermalright.com",
  seasonic: "seasonic.com",
  superflower: "super-flower.com",
  fsp: "fsp-group.com",
  enermax: "enermax.com",
  bitfenix: "bitfenix.com",
  idcooling: "idcooling.com",
  viewsonic: "viewsonic.com",
  lg: "lg.com",
  dell: "dell.com",
  hp: "hp.com",
  acer: "acer.com",
  lenovo: "lenovo.com",
  benq: "benq.com",
  aoc: "aoc.com",
  palit: "palit.com",
  gainward: "gainward.com",
  inno3d: "inno3d.com",
  kfa: "kfa2.com",
  galax: "galax.com",
  colorful: "colorful.com",
  maxsun: "maxsun.com",
  yeston: "yeston.com",
  oloy: "oloy.com",
  newegg: "newegg.com",
  patriot: "patriotmemory.com",
  siliconpower: "silicon-power.com",
  timetec: "timetec.com",
  klevv: "klevv.com",
  pccooler: "pccooler.com",
  jonsbo: "jonsbo.com",
  metallic: "metallicgear.com",
  coolman: "coolman.com",
  ssupd: "ssupd.com",
  hyte: "hyte.com",
  matorx: "matorx.com",
  streacom: "streacom.com",
  akasa: "akasa.com",
  alphel: "alphel.com",
  alphacool: "alphacool.com",
  watercool: "watercool.de",
  blacknoise: "blacknoise.de",
  noiseblocker: "noiseblocker.de",
  prolimatech: "prolimatech.com",
  raijintek: "raijintek.com",
  reeven: "reeven.com",
  swiftech: "swiftech.com",
  thermal: "thermalright.com"
};

function getBrandDomain(brand) {
  const domainKey = Object.keys(BRAND_DOMAINS).find(key => brand.includes(key));
  if (domainKey) return BRAND_DOMAINS[domainKey];
  const clean = brand.replace(/[^a-z0-9]/g, "");
  if (clean) return `${clean}.com`;
  return null;
}

export function getBrandLogo(item) {
  const brand = getBrand(item).toLowerCase();
  const domain = getBrandDomain(brand);
  if (domain) {
    return `https://logo.clearbit.com/${domain}`;
  }
  return null;
}

export function getBrandFaviconUrl(item) {
  const brand = getBrand(item).toLowerCase();
  const domain = getBrandDomain(brand);
  if (domain) {
    return `https://www.google.com/s2/favicons?domain=${domain}&sz=128`;
  }
  return null;
}

export function getBrandPlaceholder(item) {
  const brand = getBrand(item);
  return `https://placehold.co/100x100/1a1a2e/00eaff?text=${encodeURIComponent(brand.slice(0, 5))}`;
}

export function getItemImageUrl(item) {
  const raw = String(item?.image || "").split(",").filter(Boolean)[0];
  if (!raw) return null;
  if (raw.startsWith("http")) return raw;
  if (raw.startsWith("/")) return raw;
  if (raw.startsWith("thumbnails/")) return "/" + raw;
  return "/thumbnails/" + raw;
}

export function getItemImageUrls(item) {
  if (!item?.image) return [];
  return String(item.image).split(",").filter(Boolean).map(path => {
    if (path.startsWith("http")) return path;
    if (path.startsWith("/")) return path;
    if (path.startsWith("thumbnails/")) return "/" + path;
    return "/thumbnails/" + path;
  });
}

const VALID_SOCKETS = new Set(["AM4", "AM5", "LGA1700", "LGA1851", "LGA1200"]);

export function isModernComponent(categoryId, item) {
  if (!item) return true;

  switch (categoryId) {
    case "cpu": {
      const socket = inferCpuSocket(item);
      if (socket && !VALID_SOCKETS.has(socket)) return false;

      if (!socket) {
        const arch = String(item.microarchitecture || "").toLowerCase();
        const name = String(item.name || "").toLowerCase();
        if (!arch) return false;
        const oldArches = ["k10", "piledriver", "steamroller", "bulldozer", "excavator", "jaguar", "lynx", "puma+", "bobcat"];
        if (oldArches.some(a => arch.includes(a))) return false;
      }

      if (socket === "AM4") {
        const arch = String(item.microarchitecture || "").toLowerCase();
        const name = String(item.name || "").toLowerCase();
        if (arch) {
          if (arch.includes("zen 2") || arch.includes("zen+") || arch === "zen") return false;
        } else {
          const ryzenMatch = name.match(/ryzen\s*(\d)/);
          if (ryzenMatch) {
            const seriesNum = parseInt(ryzenMatch[1]);
            if (seriesNum <= 3) return false;
          }
        }
      }

      return true;
    }
    case "motherboard": {
      const socket = normalizeToken(item.socket);
      if (socket && !VALID_SOCKETS.has(socket)) return false;

      if (socket === "AM4") {
        const chipset = normalizeToken(item.chipset);
        const name = String(item.name || "").toUpperCase();
        const searchStr = chipset || name;
        if (/[ABX]3\d{2}/.test(searchStr)) return false;
      }

      return true;
    }
    case "cooler": {
      const socket = normalizeToken(item.socket);
      if (socket && socket !== "Universal" && !VALID_SOCKETS.has(socket)) return false;
      return true;
    }
    case "ram": {
      const ramType = normalizeToken(item.ram_type || item.type);
      if (ramType && !["DDR4", "DDR5"].includes(ramType)) return false;

      if (ramType === "DDR4") {
        const speed = toNumber(item.speed);
        if (speed > 0 && speed <= 3000) return false;
      }

      return true;
    }
    case "psu": {
      const wattage = toNumber(item.wattage);
      if (wattage > 0 && wattage < 450) return false;
      return true;
    }
    case "gpu": {
      return isModernGpu(item);
    }
    default:
      return true;
  }
}

export function isWindows11Compatible(cpu) {
  if (!cpu) return false;

  const socket = inferCpuSocket(cpu);
  if (!socket) return false;

  // AMD: AM5 always compatible; AM4 needs Zen+ or newer
  if (socket === "AM5") return true;
  if (socket === "AM4") {
    const arch = String(cpu.microarchitecture || "").toLowerCase();
    const name = String(cpu.name || "").toLowerCase();
    if (arch) {
      if (arch.includes("zen 3") || arch.includes("zen 2") || arch.includes("zen+") || arch.includes("zen 4") || arch.includes("zen 5")) return true;
      if (arch === "zen") return false;
    } else {
      const ryzenMatch = name.match(/ryzen\s*(\d)/);
      if (ryzenMatch) {
        const seriesNum = parseInt(ryzenMatch[1]);
        return seriesNum >= 2;
      }
    }
    return false;
  }

  // Intel: LGA1200, LGA1700, LGA1851 always compatible
  if (socket === "LGA1200" || socket === "LGA1700" || socket === "LGA1851") return true;

  // LGA1151: only Coffee Lake (8th gen) and newer
  if (socket === "LGA1151") {
    const arch = String(cpu.microarchitecture || "").toLowerCase();
    if (arch.includes("coffee lake")) return true;
    return false;
  }

  return false;
}

function isModernGpu(gpu) {
  if (!gpu) return false;
  
  const search = (String(gpu.name ?? "") + " " + String(gpu.chipset ?? "")).toUpperCase();
  const memory = toNumber(gpu.memory);
  
  // Keep at least 4GB VRAM to exclude ancient cards
  if (memory > 0 && memory < 4) return false;
  
  // NVIDIA RTX — any RTX card is modern enough
  if (search.includes("RTX")) return true;
  
  // NVIDIA GTX — only allow GTX 16-series (1650, 1660, 1660 Super, 1660 Ti)
  if (search.includes("GTX")) {
    if (search.includes("GTX 16") || search.includes("GTX16") || search.includes("1660") || search.includes("1650")) return true;
    return false;
  }
  
  // AMD RX — RX 500 series or newer
  const rxMatch = search.match(/RX\s*(\d{4})/);
  if (rxMatch) {
    const tier = parseInt(rxMatch[1]);
    return tier >= 500;
  }
  
  // Radeon PRO / Pro WX series
  if (search.includes("PRO")) return true;
  
  // Intel Arc
  if (search.includes("ARC")) return true;
  
  // NVIDIA Quadro / RTX A-series / T-series
  if (search.includes("QUADRO") || search.includes("RTX A") || search.startsWith("T")) return true;
  
  return false;
}
