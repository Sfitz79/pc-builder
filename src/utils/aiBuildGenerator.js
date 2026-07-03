import { loadCSV } from "./loadCSV";
import { inferCpuSocket } from "./common";

const REQUIRED_CATEGORIES = ["case", "case-fan", "cooler", "cpu", "motherboard", "ram", "storage", "psu", "os"];

const STREAMING_REQUIRED = ["webcam", "headphones"];

const OPTIONAL_EXTRA_CATEGORIES = ["wireless-network-card"];

const ALL_CATEGORIES = [
  { id: "cpu", file: "cpu.csv", label: "CPU", weight: 0.3 },
  { id: "motherboard", file: "motherboard.csv", label: "Motherboard", weight: 0.1 },
  { id: "cooler", file: "cooler.csv", label: "CPU Cooler", weight: 0.05 },
  { id: "ram", file: "ram.csv", label: "RAM", weight: 0.1 },
  { id: "storage", file: "storage.csv", label: "Storage", weight: 0.05 },
  { id: "gpu", file: "gpu.csv", label: "GPU", weight: 0.3 },
  { id: "case", file: "case.csv", label: "Case", weight: 0.04 },
  { id: "case-fan", file: "case-fan.csv", label: "Case Fan", weight: 0.01 },
  { id: "psu", file: "power-supply.csv", label: "Power Supply", weight: 0.05 },
  { id: "os", file: "os.csv", label: "Operating System", weight: 0.0 },
  { id: "monitor", file: "monitor.csv", label: "Monitor", weight: 0.0 },
  { id: "headphones", file: "headphones.csv", label: "Headphones", weight: 0.0 },
  { id: "keyboard", file: "keyboard.csv", label: "Keyboard", weight: 0.0 },
  { id: "mouse", file: "mouse.csv", label: "Mouse", weight: 0.0 },
  { id: "speakers", file: "speakers.csv", label: "Speakers", weight: 0.0 },
  { id: "webcam", file: "webcam.csv", label: "Webcam", weight: 0.0 },
  { id: "wireless-network-card", file: "wireless-network-card.csv", label: "WiFi Card", weight: 0.0 },
];

export function getRamDdr(ram) {
  const gen = parseInt(ram.speed) || 0;
  return gen >= 5 ? "DDR5" : "DDR4";
}

function isDdr5(ram) {
  return getRamDdr(ram) === "DDR5";
}

function isSsd(item) {
  const t = (item.type || "").toLowerCase();
  return t.includes("ssd") || t.includes("nvme") || t.includes("m.2") || t.includes("solid");
}

function isHdd(item) {
  const t = (item.type || "").toLowerCase();
  const n = (item.name || "").toLowerCase();
  return t.includes("hdd") || t.includes("hard") || t.includes("5400") || t.includes("7200") || n.includes("hdd");
}

export async function generateBuild(budget, useCase, color = "any", options = {}) {
  const {
    needMonitor = true,
    monitorResolution = "auto",
    needMouse = true,
    needKeyboard = true,
    needSpeakers = true,
    needWifi = true,
    consumerOnly = false,
    preferDDR4 = false,
    dualStorage = false,
  } = options;

  const SKIP_CATEGORIES = new Set();
  if (!needMonitor) SKIP_CATEGORIES.add("monitor");
  if (!needMouse) SKIP_CATEGORIES.add("mouse");
  if (!needKeyboard) SKIP_CATEGORIES.add("keyboard");
  if (!needSpeakers) SKIP_CATEGORIES.add("speakers");
  if (!needWifi) SKIP_CATEGORIES.add("wireless-network-card");

  const allParts = {};
  for (const cat of ALL_CATEGORIES) {
    const items = await loadCSV(cat.file);
    allParts[cat.id] = items.filter(item => {
      const price = parseFloat(item.price);
      return !isNaN(price) && price >= 0;
    });
  }

  const budgetAllocation = allocateBudget(budget, useCase);
  const build = {};

  for (const cat of ALL_CATEGORIES) {
    if (SKIP_CATEGORIES.has(cat.id)) continue;

    let catBudget = budgetAllocation[cat.id];
    let candidates = allParts[cat.id].filter(item => {
      const price = parseFloat(item.price);
      return price <= catBudget * 1.15;
    });

    if (candidates.length === 0) {
      candidates = [...allParts[cat.id]].sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
      if (candidates.length > 0) catBudget = parseFloat(candidates[candidates.length - 1].price);
    }

    if (cat.id === "cpu") {
      candidates = filterCpuForUseCase(candidates, useCase);
      if (consumerOnly) {
        candidates = candidates.filter(c => {
          const name = (c.name || "").toUpperCase();
          if (name.includes("THREADRIPPER")) return false;
          if (name.includes("XEON")) return false;
          if (name.includes("EPYC")) return false;
          return true;
        });
      }
    }
    if (cat.id === "ram") {
      candidates = filterRamForUseCase(candidates, useCase);
      if (preferDDR4) {
        candidates.sort((a, b) => {
          const aIsDdr5 = isDdr5(a);
          const bIsDdr5 = isDdr5(b);
          if (aIsDdr5 && !bIsDdr5) return 1;
          if (!aIsDdr5 && bIsDdr5) return -1;
          return parseFloat(a.price) - parseFloat(b.price);
        });
      }
    }
    if (cat.id === "storage") {
      if (dualStorage) {
        const totalStorageBudget = catBudget + (budgetAllocation["storage_hdd"] || 0);
        const ssdPool = candidates.filter(isSsd);
        const filteredSsds = filterStorageForUseCase(ssdPool, useCase);
        const ssds = filteredSsds.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
        const hddPool = allParts[cat.id].filter(item => {
          const p = parseFloat(item.price);
          return !isNaN(p) && p >= 0 && p <= totalStorageBudget * 1.15;
        });
        const hdds = hddPool.filter(isHdd).filter(h => {
          const capacityGB = (parseFloat(h.capacity) || 0) * 1000;
          return capacityGB >= 500;
        }).sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
        if (ssds.length > 0) {
          const ssdBudget = totalStorageBudget * 0.6;
          const chosenSsd = ssds.filter(s => parseFloat(s.price) <= ssdBudget).sort((a, b) => {
            const aVal = parseFloat(a.price);
            const bVal = parseFloat(b.price);
            return Math.abs(aVal - ssdBudget) - Math.abs(bVal - ssdBudget);
          })[0] || ssds[0];
          build["storage"] = chosenSsd;
          const remaining = totalStorageBudget - parseFloat(chosenSsd.price);
          if (hdds.length > 0 && remaining > 20) {
            const chosenHdd = hdds.filter(h => parseFloat(h.price) <= remaining).sort((a, b) => {
              const aVal = parseFloat(a.price);
              const bVal = parseFloat(b.price);
              return Math.abs(aVal - remaining * 0.8) - Math.abs(bVal - remaining * 0.8);
            })[0] || null;
            if (chosenHdd) build["storage_hdd"] = chosenHdd;
          }
          continue;
        }
        candidates = filterStorageForUseCase(candidates, useCase);
      } else {
        candidates = filterStorageForUseCase(candidates, useCase);
      }
    }

    if (cat.id === "motherboard" && build.cpu) {
      const cpuSocket = inferCpuSocket(build.cpu);
      if (cpuSocket) {
        candidates = candidates.filter(m => {
          const moboSocket = (m.socket || "").toUpperCase().trim();
          return moboSocket === cpuSocket;
        });
      }
    }

    if (cat.id === "cooler" && build.cpu) {
      const cpuTdp = parseFloat(build.cpu.tdp) || 65;
      if (cpuTdp > 150) {
        candidates = candidates.filter(c => (parseFloat(c.size) || 0) >= 200 || parseFloat(c.price) >= catBudget * 0.3);
      } else if (cpuTdp > 100) {
        candidates = candidates.filter(c => (parseFloat(c.size) || 0) >= 120 || parseFloat(c.price) >= catBudget * 0.15);
      } else if (cpuTdp > 65) {
        candidates = candidates.filter(c => (parseFloat(c.size) || 0) >= 60 || parseFloat(c.price) >= catBudget * 0.1);
      }
    }

    if (cat.id === "gpu") {
      const needsGpu = gpuRequired(useCase, build.cpu);
      if (!needsGpu) {
        continue;
      }
      candidates = filterGpuForUseCase(candidates, useCase);
      if (monitorResolution === "1440p") {
        candidates = candidates.filter(g => (parseFloat(g.memory) || 0) >= 8);
      } else if (monitorResolution === "4k") {
        candidates = candidates.filter(g => (parseFloat(g.memory) || 0) >= 12);
      }
    }

    if (cat.id === "psu") {
      const totalWattage = estimateTotalWattage(build);
      candidates = candidates.filter(p => parseFloat(p.wattage) >= totalWattage);
    }

    if (cat.id === "os") {
      const allOs = allParts["os"];
      const winPro = allOs.filter(o => /windows.*11.*pro/i.test(o.name));
      if (winPro.length > 0) {
        candidates = winPro.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
      }
    }

    if (cat.id === "case" && build.motherboard) {
      const moboFormFactor = (build.motherboard.form_factor || "").toUpperCase();
      candidates = candidates.filter(c => {
        const caseType = (c.type || "").toUpperCase();
        if (moboFormFactor === "EATX") {
          return caseType.includes("EATX") || caseType.includes("FULL");
        }
        if (moboFormFactor === "ATX") {
          return caseType.startsWith("ATX") || caseType.includes("EATX") || caseType === "HTPC";
        }
        if (moboFormFactor === "MICRO ATX") {
          return caseType.includes("MICRO ATX") || caseType.includes("ATX/MICRO") || caseType === "HTPC";
        }
        if (moboFormFactor === "MINI ITX" || moboFormFactor === "MINI DTX") {
          return caseType.includes("ITX") || caseType.includes("MICRO ATX") || caseType.includes("ATX/MICRO");
        }
        return true;
      });
      if (color !== "any") {
        candidates = candidates.filter(c => {
          const cColor = (c.color || "").toLowerCase();
          return cColor.includes(color.toLowerCase());
        });
      }
    }

    if (cat.id === "headphones" && isStreamingUseCase(useCase)) {
      candidates = candidates.filter(h => {
        const hasMic = String(h.microphone).toLowerCase();
        return hasMic === "true" || hasMic === "yes" || hasMic === "1";
      });
    }

    if (cat.id === "monitor") {
      const targetVerticalRes = pickMonitorResolution(monitorResolution, budget, useCase);
      if (targetVerticalRes > 0) {
        candidates = candidates.filter(m => {
          const vertRes = parseInt(m.refresh_rate) || 0;
          return vertRes >= targetVerticalRes || (parseInt(m.resolution) || 0) >= 2560;
        });
      }
    }

    if (cat.id !== "storage" || !dualStorage || !build.storage) {
      candidates.sort((a, b) => {
        const aPrice = parseFloat(a.price);
        const bPrice = parseFloat(b.price);
        const aDiff = Math.abs(aPrice - catBudget);
        const bDiff = Math.abs(bPrice - catBudget);
        return aDiff - bDiff;
      });

      if (candidates.length > 0) {
        build[cat.id] = candidates[0];
      }
    }
  }

  for (const catId of REQUIRED_CATEGORIES) {
    if (!build[catId]) {
      let candidates = [...allParts[catId]];
      if (catId === "os") {
        const winPro = candidates.filter(o => /windows.*11.*pro/i.test(o.name));
        if (winPro.length > 0) candidates = winPro;
      }
      candidates.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
      if (candidates.length > 0) {
        build[catId] = candidates[0];
      }
    }
  }

  if (isStreamingUseCase(useCase)) {
    for (const catId of STREAMING_REQUIRED) {
      if (!build[catId]) {
        let candidates = [...allParts[catId]];
        if (catId === "headphones") {
          candidates = candidates.filter(h => {
            const hasMic = String(h.microphone).toLowerCase();
            return hasMic === "true" || hasMic === "yes" || hasMic === "1";
          });
        }
        candidates.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
        if (candidates.length > 0) {
          build[catId] = candidates[0];
        }
      }
    }
  }

  return build;
}

export async function generateRecommendedBuild(budget, useCase, color = "any", options = {}) {
  const recBudget = Math.max(budget * 2, 2500);
  const opts = { ...options, consumerOnly: true, dualStorage: true };
  return generateBuild(recBudget, useCase, color, opts);
}

export function explainBuild(build, label, useCase, budget) {
  const cpu = build.cpu?.name || "a capable CPU";
  const gpu = build.gpu?.name || "integrated graphics";
  const ram = build.ram?.name || "RAM";
  const ramSize = parseRamGB(ram);
  const ramDdr = getRamDdr(build.ram);
  const total = Object.values(build).reduce((s, item) => s + (parseFloat(item.price) || 0), 0);
  const use = (useCase || "").toLowerCase();
  const isGaming = use.includes("gaming");
  const isStream = use.includes("stream");
  const isWork = use.includes("workstation") || use.includes("creation") || use.includes("render");

  const parts = [];

  if (label === "PCTG Recommends") {
    parts.push(`This is our top pick — the best PC we can build for your needs. It goes beyond your £${budget.toLocaleString('en-GB')} budget because we believe in spending wisely where it counts most.`);
  } else {
    const pct = Math.round((total / budget) * 100);
    parts.push(`This build uses about ${pct}% of your £${budget.toLocaleString('en-GB')} budget.`);
  }

  if (isGaming || isStream) {
    parts.push(`The ${gpu.split("(")[0].trim()} graphics card is the heart of this system — it determines how sharp your games look and how smoothly they run. Paired with the ${cpu.split("(")[0].trim()}, you get great frame rates without bottlenecks.`);
  }

  if (ramSize >= 32) {
    parts.push(`With ${ramSize}GB of ${ramDdr} RAM, you have plenty of memory for multitasking — keep Discord, Chrome, and your game open without slowdowns.`);
  } else if (ramSize >= 16) {
    parts.push(`The ${ramSize}GB ${ramDdr} RAM is the sweet spot for most users — enough for modern games and everyday tasks without overspending.`);
  } else {
    parts.push(`With ${ramSize}GB of ${ramDdr} RAM, this covers basic use. Consider upgrading to 16GB if you multitask heavily.`);
  }

  if (isWork) {
    parts.push(`For content creation or workstation tasks, the ${cpu.split("(")[0].trim()} provides the multi-core muscle needed for rendering, encoding, and compiling.`);
  }

  if (build.storage) {
    const mainDrive = build.storage.name || "";
    if (build.storage_hdd) {
      parts.push(`Storage combines a fast ${mainDrive} for your operating system and favourite apps, plus a ${build.storage_hdd.name || "large hard drive"} for mass storage — best of both worlds.`);
    } else {
      parts.push(`The ${mainDrive} gives you fast boot times and snappy app loading.`);
    }
  }

  if (label === "Budget-Friendly") {
    parts.push(`This is the most wallet-friendly option. We picked parts that give you the best bang for your buck — you might need to lower some graphics settings in demanding games, but the experience will still be smooth.`);
  } else if (label === "Recommended") {
    parts.push(`This is our balanced recommendation — great performance without stretching your budget. You'll run most games at high settings with solid frame rates.`);
  } else if (label === "Premium") {
    parts.push(`This is the premium choice. We've pushed the budget higher to get you top-tier components that will stay relevant for years. Expect max settings at your target resolution.`);
  } else if (label === "PCTG Recommends") {
    parts.push(`We've selected the CPU and GPU that deliver the best real-world performance for your type of workload. This isn't about specs on paper — it's about how it feels when you use it.`);
  }

  return parts.join(" ");
}

function parseRamGB(name) {
  if (!name) return 0;
  const m = name.match(/(\d+)\s*GB/i);
  return m ? parseInt(m[1], 10) : 0;
}

function pickMonitorResolution(requested, budget, useCase) {
  if (requested === "1080p") return 1080;
  if (requested === "1440p") return 1440;
  if (requested === "4k") return 2160;
  const use = (useCase || "").toLowerCase();
  const isGaming = use.includes("gaming") || use.includes("stream");
  if (!isGaming) {
    if (budget < 1200) return 1080;
    return 1440;
  }
  if (budget < 800) return 1080;
  if (budget < 2000) return 1440;
  return 2160;
}

function isStreamingUseCase(useCase) {
  return (useCase || "").toLowerCase().includes("stream");
}

function gpuRequired(useCase, cpu) {
  const use = (useCase || "").toLowerCase();
  const isBasic = use.includes("general");
  if (!isBasic) return true;
  const igpu = (cpu?.integrated_graphics || "").toLowerCase();
  return igpu === "none" || igpu === "";
}

function allocateBudget(totalBudget, useCase) {
  const gamingAlloc = { cpu: 0.20, gpu: 0.33, motherboard: 0.09, cooler: 0.04, ram: 0.09, storage: 0.04, "case-fan": 0.01, "storage_hdd": 0.02, psu: 0.05, os: 0.02, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.01, webcam: 0.01 };
  const streamingAlloc = { cpu: 0.22, gpu: 0.30, motherboard: 0.09, cooler: 0.05, ram: 0.09, storage: 0.05, "case-fan": 0.01, "storage_hdd": 0.02, psu: 0.04, os: 0.02, monitor: 0.04, headphones: 0.02, keyboard: 0.01, mouse: 0.01, speakers: 0.01, webcam: 0.01 };
  const workstationAlloc = { cpu: 0.25, gpu: 0.26, motherboard: 0.09, cooler: 0.06, ram: 0.09, storage: 0.06, "case-fan": 0.01, "storage_hdd": 0.03, psu: 0.04, os: 0.02, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.0, webcam: 0.01 };
  const contentCreationAlloc = { cpu: 0.23, gpu: 0.25, motherboard: 0.08, cooler: 0.05, ram: 0.10, storage: 0.08, "case-fan": 0.01, "storage_hdd": 0.03, psu: 0.04, os: 0.02, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.0, webcam: 0.01 };
  const generalAlloc = { cpu: 0.20, gpu: 0.25, motherboard: 0.10, cooler: 0.05, ram: 0.10, storage: 0.06, "case-fan": 0.01, "storage_hdd": 0.02, psu: 0.06, os: 0.02, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.01, webcam: 0.01 };

  let alloc = generalAlloc;
  const use = (useCase || "").toLowerCase();
  if (use.includes("gaming") && use.includes("stream")) alloc = streamingAlloc;
  else if (use.includes("gaming")) alloc = gamingAlloc;
  else if (use.includes("creation")) alloc = contentCreationAlloc;
  else if (use.includes("stream") || use.includes("content")) alloc = streamingAlloc;
  else if (use.includes("workstation") || use.includes("render") || use.includes("video") || use.includes("3d") || use.includes("cad")) alloc = workstationAlloc;

  const allocation = {};
  for (const [cat, pct] of Object.entries(alloc)) {
    allocation[cat] = totalBudget * pct;
  }
  return allocation;
}

function filterCpuForUseCase(cpus, useCase) {
  const use = (useCase || "").toLowerCase();
  const isGaming = use.includes("gaming");
  const isWorkstation = use.includes("workstation") || use.includes("render") || use.includes("video") || use.includes("creation");
  const isStreaming = use.includes("stream");

  return cpus.filter(cpu => {
    const cores = parseFloat(cpu.core_count) || 0;
    if (isWorkstation && cores < 6) return false;
    if (isStreaming && cores < 6) return false;
    if (isGaming && cores < 4) return false;
    return true;
  });
}

function filterGpuForUseCase(gpus, useCase) {
  const use = (useCase || "").toLowerCase();
  const isGaming = use.includes("gaming");
  const isWorkstation = use.includes("workstation") || use.includes("render") || use.includes("video") || use.includes("3d") || use.includes("creation");

  return gpus.filter(gpu => {
    const vram = parseFloat(gpu.memory) || 0;
    if (isWorkstation && vram < 8) return false;
    if (isGaming && vram < 4) return false;
    return true;
  });
}

function getRamCapacityGB(ram) {
  if (!ram?.name) return 0;
  const m = ram.name.match(/(\d+)\s*GB/i);
  return m ? parseInt(m[1], 10) : 0;
}

function getRamMhzSpeed(ram) {
  const mhz = parseInt(ram.modules) || 0;
  return mhz;
}

function filterRamForUseCase(rams, useCase) {
  const use = (useCase || "").toLowerCase();
  const isHeavy = use.includes("workstation") || use.includes("render") || use.includes("video") || use.includes("stream") || use.includes("creation");

  return rams.filter(ram => {
    const totalGB = getRamCapacityGB(ram);
    const mhzSpeed = getRamMhzSpeed(ram);

    if (isHeavy && totalGB < 32) return false;
    if (use.includes("gaming") && totalGB < 16) return false;
    if (isHeavy && !use.includes("workstation") && mhzSpeed < 5200) return false;
    return true;
  });
}

function filterStorageForUseCase(storages, useCase) {
  const use = (useCase || "").toLowerCase();
  const isHeavy = use.includes("workstation") || use.includes("video") || use.includes("render") || use.includes("gaming") || use.includes("creation");

  return storages.filter(s => {
    const capacityGB = (parseFloat(s.capacity) || 0) * 1000;
    if (isHeavy && capacityGB < 1000) return false;
    if (capacityGB < 240) return false;
    return true;
  });
}

function estimateTotalWattage(build) {
  let total = 100;
  if (build.cpu) total += parseFloat(build.cpu.tdp) || 65;
  if (build.gpu) total += parseFloat(build.gpu.tdp) || 150;
  if (build.ram) total += 10;
  if (build.storage) total += 10;
  if (build.storage_hdd) total += 10;
  if (build.cooler) total += parseFloat(build.cooler.wattage) || 10;
  return total + 150;
}
