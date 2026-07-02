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

export async function generateBuild(budget, useCase, color = "any", options = {}) {
  const {
    needMonitor = true,
    monitorResolution = "auto",
    needMouse = true,
    needKeyboard = true,
    needSpeakers = true,
    needWifi = true,
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

    const catBudget = budgetAllocation[cat.id];
    let candidates = allParts[cat.id].filter(item => {
      const price = parseFloat(item.price);
      return price <= catBudget * 1.15;
    });

    if (cat.id === "cpu") {
      candidates = filterCpuForUseCase(candidates, useCase);
    }
    if (cat.id === "ram") {
      candidates = filterRamForUseCase(candidates, useCase);
    }
    if (cat.id === "storage") {
      candidates = filterStorageForUseCase(candidates, useCase);
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
      candidates = candidates.filter(c => {
        const radSize = parseInt(c.radiator_size) || 0;
        if (radSize > 0) {
          if (cpuTdp > 150 && radSize < 360) return false;
          if (cpuTdp > 100 && radSize < 240) return false;
          if (cpuTdp > 65 && radSize < 120) return false;
        }
        return true;
      });
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
        if (moboFormFactor.includes("EATX") && !caseType.includes("EATX") && !caseType.includes("FULL")) return false;
        if (moboFormFactor.includes("ATX") && !caseType.includes("ATX") && !caseType.includes("FULL") && !caseType.includes("MID")) return false;
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
          const horizRes = parseInt(m.resolution) || 0;
          const vertRes = parseInt(m.refresh_rate) || 0;
          return vertRes >= targetVerticalRes || horizRes >= 2560;
        });
      }
    }

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
  const gamingAlloc = { cpu: 0.20, gpu: 0.33, motherboard: 0.09, cooler: 0.04, ram: 0.09, storage: 0.04, case: 0.03, "case-fan": 0.01, psu: 0.05, os: 0.02, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.01, webcam: 0.01 };
  const streamingAlloc = { cpu: 0.22, gpu: 0.30, motherboard: 0.09, cooler: 0.05, ram: 0.09, storage: 0.05, case: 0.03, "case-fan": 0.01, psu: 0.04, os: 0.02, monitor: 0.04, headphones: 0.02, keyboard: 0.01, mouse: 0.01, speakers: 0.01, webcam: 0.01 };
  const workstationAlloc = { cpu: 0.25, gpu: 0.26, motherboard: 0.09, cooler: 0.06, ram: 0.09, storage: 0.06, case: 0.03, "case-fan": 0.01, psu: 0.04, os: 0.02, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.0, webcam: 0.01 };
  const contentCreationAlloc = { cpu: 0.23, gpu: 0.25, motherboard: 0.08, cooler: 0.05, ram: 0.10, storage: 0.08, case: 0.03, "case-fan": 0.01, psu: 0.04, os: 0.02, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.0, webcam: 0.01 };
  const generalAlloc = { cpu: 0.20, gpu: 0.25, motherboard: 0.10, cooler: 0.05, ram: 0.10, storage: 0.06, case: 0.04, "case-fan": 0.01, psu: 0.06, os: 0.02, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.01, webcam: 0.01 };

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

function getRamDdrGen(ram) {
  return parseInt(ram.speed) || 0;
}

function filterRamForUseCase(rams, useCase) {
  const use = (useCase || "").toLowerCase();
  const isHeavy = use.includes("workstation") || use.includes("render") || use.includes("video") || use.includes("stream") || use.includes("creation");

  return rams.filter(ram => {
    const totalGB = getRamCapacityGB(ram);
    const mhzSpeed = getRamMhzSpeed(ram);
    const ddrGen = getRamDdrGen(ram);

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
  if (build.cooler) total += parseFloat(build.cooler.wattage) || 10;
  return total + 150;
}
