import { loadCSV } from "./loadCSV";
import { inferCpuSocket, isModernComponent, isWindows11Compatible } from "./common";
import { filterCasesByStyle, filterCasesByColor } from "./caseStyles";

const REQUIRED_CATEGORIES = ["case", "case-fan", "cooler", "cpu", "motherboard", "ram", "ssd", "psu", "os", "thermal-paste"];

const STREAMING_REQUIRED = ["webcam", "headphones"];

const OPTIONAL_EXTRA_CATEGORIES = ["wireless-network-card"];

const ALL_CATEGORIES = [
  { id: "cpu", file: "cpu.csv", label: "CPU", weight: 0.3 },
  { id: "cooler", file: "cooler.csv", label: "CPU Cooler", weight: 0.05 },
  { id: "motherboard", file: "motherboard.csv", label: "Motherboard", weight: 0.1 },
  { id: "case", file: "case.csv", label: "Case", weight: 0.04 },
  { id: "ram", file: "ram.csv", label: "RAM", weight: 0.1 },
  { id: "gpu", file: "gpu.csv", label: "GPU", weight: 0.3 },
  { id: "ssd", file: "ssd.csv", label: "SSD", weight: 0.03 },
  { id: "mass-storage", file: "mass-storage.csv", label: "Mass Storage", weight: 0.02 },
  { id: "psu", file: "power-supply.csv", label: "Power Supply", weight: 0.05 },
  { id: "os", file: "os.csv", label: "Operating System", weight: 0.0 },
  { id: "wireless-network-card", file: "wireless-network-card.csv", label: "WiFi Card", weight: 0.0 },
  { id: "monitor", file: "monitor.csv", label: "Monitor", weight: 0.0 },
  { id: "keyboard", file: "keyboard.csv", label: "Keyboard", weight: 0.0 },
  { id: "mouse", file: "mouse.csv", label: "Mouse", weight: 0.0 },
  { id: "speakers", file: "speakers.csv", label: "Speakers", weight: 0.0 },
  { id: "headphones", file: "headphones.csv", label: "Headphones", weight: 0.0 },
  { id: "case-fan", file: "case-fan.csv", label: "Case Fan", weight: 0.0 },
  { id: "thermal-paste", file: "thermal-paste.csv", label: "Thermal Paste", weight: 0.0 },
  { id: "fan-controller", file: "fan-controller.csv", label: "Fan Controller", weight: 0.0 },
  { id: "webcam", file: "webcam.csv", label: "Webcam", weight: 0.0 },
  { id: "streaming", file: "Streaming.csv", label: "Streaming Equipment", weight: 0.0 },
  { id: "flight-simulation", file: "flight-simulation.csv", label: "Flight Simulation", weight: 0.0 },
  { id: "racing-simulation", file: "racing-simulation.csv", label: "Racing Simulation", weight: 0.0 },
  { id: "game-controllers", file: "game-controllers.csv", label: "Game Controllers", weight: 0.0 },
  { id: "cables-and-accessories", file: "cables-and-accessories.csv", label: "Cables & Accessories", weight: 0.0 },
  { id: "optical-drive", file: "optical-drive.csv", label: "Optical Drive", weight: 0.0 },
  { id: "ups", file: "ups.csv", label: "UPS", weight: 0.0 },
];

export function getRamDdr(ram) {
  const type = String(ram.ram_type || "").toUpperCase();
  if (type.includes("DDR5")) return "DDR5";
  if (type.includes("DDR4")) return "DDR4";
  if (type.includes("DDR3")) return "DDR3";

  const speed = String(ram.speed || "").toUpperCase();
  if (speed.includes("DDR5")) return "DDR5";
  if (speed.includes("DDR4")) return "DDR4";
  if (speed.includes("DDR3")) return "DDR3";

  return "DDR4";
}

function isModernGpu(gpu) {
  const memory = parseFloat(gpu.memory) || 0;
  if (memory > 0 && memory < 6) return false;

  const chipset = String(gpu.chipset || "").toUpperCase();
  if (chipset.includes("ARC B") || chipset.includes("ARC A")) return true;
  if (chipset.includes("RADEON RX")) {
    const m = chipset.match(/RX\s*(\d)/);
    if (m && parseInt(m[1]) >= 6) return true;
  }
  if (chipset.includes("GEFORCE RTX")) {
    const m = chipset.match(/RTX\s*(\d{2})/);
    if (m && parseInt(m[1]) >= 30) return true;
  }
  return false;
}

function isDdr5(ram) {
  return getRamDdr(ram) === "DDR5";
}

function isSsd(item) {
  const t = String(item.type || "").toLowerCase();
  return t.includes("ssd") || t.includes("nvme") || t.includes("m.2") || t.includes("solid");
}

function isHdd(item) {
  const t = String(item.type || "").toLowerCase();
  const n = String(item.name || "").toLowerCase();
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
    caseStyle = "any",
    needStreaming = false,
    needFlightSim = false,
    needRacingSim = false,
    needGameControllers = false,
    needCablesAccessories = false,
    needFanController = false,
    needOpticalDrive = false,
    needUps = false,
  } = options;

  const SKIP_CATEGORIES = new Set();
  if (!needMonitor) SKIP_CATEGORIES.add("monitor");
  if (!needMouse) SKIP_CATEGORIES.add("mouse");
  if (!needKeyboard) SKIP_CATEGORIES.add("keyboard");
  if (!needSpeakers) SKIP_CATEGORIES.add("speakers");
  if (!needWifi) SKIP_CATEGORIES.add("wireless-network-card");
  if (!needStreaming) SKIP_CATEGORIES.add("streaming");
  if (!needFlightSim) SKIP_CATEGORIES.add("flight-simulation");
  if (!needRacingSim) SKIP_CATEGORIES.add("racing-simulation");
  if (!needGameControllers) SKIP_CATEGORIES.add("game-controllers");
  if (!needCablesAccessories) SKIP_CATEGORIES.add("cables-and-accessories");
  if (!needFanController) SKIP_CATEGORIES.add("fan-controller");
  if (!needOpticalDrive) SKIP_CATEGORIES.add("optical-drive");
  if (!needUps) SKIP_CATEGORIES.add("ups");

  const allParts = {};
  for (const cat of ALL_CATEGORIES) {
    const items = await loadCSV(cat.file);
    allParts[cat.id] = items.filter(item => {
      const price = parseFloat(item.price);
      return !isNaN(price) && price >= 0;
    });
  }

  const budgetAllocation = allocateBudget(budget, useCase);
  const workload = classifyWorkload(useCase);

  if (budgetAllocation["os"] < 50) {
    const overage = 50 - budgetAllocation["os"];
    budgetAllocation["os"] = 50;
    const otherCats = Object.keys(budgetAllocation).filter(k => k !== "os");
    const totalOther = otherCats.reduce((s, k) => s + budgetAllocation[k], 0);
    if (totalOther > 0) {
      for (const cat of otherCats) {
        budgetAllocation[cat] -= budgetAllocation[cat] / totalOther * overage;
      }
    }
  }

  const build = {};

  for (const cat of ALL_CATEGORIES) {
    if (SKIP_CATEGORIES.has(cat.id)) continue;

    let catBudget = budgetAllocation[cat.id];
    let candidates = allParts[cat.id].filter(item => {
      const price = parseFloat(item.price);
      return price <= catBudget;
    });

    if (candidates.length === 0) {
      candidates = allParts[cat.id].filter(item => {
        const price = parseFloat(item.price);
        return price <= catBudget * 1.15;
      });
    }

    if (candidates.length === 0) {
      candidates = [...allParts[cat.id]].sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
      if (candidates.length > 0) catBudget = parseFloat(candidates[candidates.length - 1].price);
    }

    if (cat.id === "cpu") {
      candidates = filterCpuForUseCase(candidates, useCase, monitorResolution);
      if (consumerOnly) {
        candidates = candidates.filter(c => {
          const name = (c.name || "").toUpperCase();
          if (name.includes("THREADRIPPER")) return false;
          if (name.includes("XEON")) return false;
          if (name.includes("EPYC")) return false;
          const socket = inferCpuSocket(c);
          if (socket && (socket === "LGA1151" || socket === "LGA1150" || socket === "LGA1155" || socket === "LGA775" || socket === "LGA1200")) return false;
          return true;
        });
      }
    }
    if (cat.id === "ram") {
      candidates = filterRamForUseCase(candidates, useCase);
      if (build.motherboard) {
        const moboGen = (() => {
          const t = String(build.motherboard.ram_type || "").toLowerCase();
          const n = String(build.motherboard.name || "").toUpperCase();
          const s = String(build.motherboard.socket || "").toUpperCase();
          if (t.includes("ddr5") || n.includes("DDR5") || s === "AM5" || s === "LGA1851") return "DDR5";
          if (t.includes("ddr4") || n.includes("DDR4") || n.includes(" D4")) return "DDR4";
          return null;
        })();
        if (moboGen === "DDR5") {
          candidates = candidates.filter(c => isDdr5(c));
        } else if (moboGen === "DDR4") {
          candidates = candidates.filter(c => !isDdr5(c));
        }
      }
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
    if (cat.id === "ssd") {
      if (dualStorage) {
        const totalStorageBudget = catBudget + (budgetAllocation["mass-storage"] || 0);
        const ssdPool = candidates;
        const filteredSsds = filterStorageForUseCase(ssdPool, useCase);
        const ssds = filteredSsds.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
        if (ssds.length > 0) {
          const ssdBudget = totalStorageBudget * 0.6;
          const chosenSsd = ssds.filter(s => parseFloat(s.price) <= ssdBudget).sort((a, b) => {
            const aVal = parseFloat(a.price);
            const bVal = parseFloat(b.price);
            return Math.abs(aVal - ssdBudget) - Math.abs(bVal - ssdBudget);
          })[0] || ssds[0];
          build["ssd"] = chosenSsd;
          continue;
        }
        candidates = filterStorageForUseCase(candidates, useCase);
      } else {
        candidates = filterStorageForUseCase(candidates, useCase);
      }
    }
    if (cat.id === "mass-storage") {
      if (dualStorage && build.ssd) {
        const totalStorageBudget = (budgetAllocation["ssd"] || 0) + catBudget;
        const remaining = totalStorageBudget - parseFloat(build.ssd.price || 0);
        const hddPool = candidates.filter(item => {
          const p = parseFloat(item.price);
          return !isNaN(p) && p >= 0 && p <= remaining * 1.15;
        });
        const hdds = hddPool.filter(h => {
          const capacityGB = (parseFloat(h.capacity) || 0) * 1000;
          return capacityGB >= 500;
        }).sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
        if (hdds.length > 0 && remaining > 20) {
          const chosenHdd = hdds.filter(h => parseFloat(h.price) <= remaining).sort((a, b) => {
            const aVal = parseFloat(a.price);
            const bVal = parseFloat(b.price);
            return Math.abs(aVal - remaining * 0.8) - Math.abs(bVal - remaining * 0.8);
          })[0] || null;
          if (chosenHdd) build["mass-storage"] = chosenHdd;
        }
        continue;
      }
    }

    if (cat.id === "motherboard" && build.cpu) {
      const cpuSocket = inferCpuSocket(build.cpu);
      if (cpuSocket) {
        candidates = candidates.filter(m => {
          const moboSocket = String(m.socket || "").toUpperCase().trim();
          return moboSocket === cpuSocket;
        });
      }
      if (needWifi) {
        const wifiCandidates = candidates.filter(m => {
          const name = String(m.name || "").toLowerCase();
          return name.includes("wifi") || name.includes("wi-fi") || name.includes("wireless");
        });
        if (wifiCandidates.length > 0) candidates = wifiCandidates;
      }
    }

    if (cat.id === "cooler" && build.cpu) {
      const cpuTdp = parseFloat(build.cpu.tdp) || 65;
      if (cpuTdp > 150) {
        candidates = candidates.filter(c => {
          const radMm = parseRadiatorMm(c);
          return radMm >= 240 || parseFloat(c.price) >= catBudget * 0.3;
        });
      } else if (cpuTdp > 100) {
        candidates = candidates.filter(c => {
          const radMm = parseRadiatorMm(c);
          return radMm >= 120 || parseFloat(c.price) >= catBudget * 0.15;
        });
      } else if (cpuTdp > 65) {
        candidates = candidates.filter(c => {
          const radMm = parseRadiatorMm(c);
          return radMm >= 60 || parseFloat(c.price) >= catBudget * 0.1;
        });
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
      {
        const hasIgpu = (() => {
          const igpu = String(build.cpu?.graphics || "").toLowerCase();
          return igpu && igpu !== "none" && igpu !== "false" && igpu !== "";
        })();
        const modernCandidates = candidates.filter(isModernGpu);
        if (modernCandidates.length > 0) {
          candidates = modernCandidates;
        } else if (hasIgpu) {
          continue;
        }
      }
    }

    if (cat.id === "gpu" && build.cpu) {
      if (candidates.length > 0) {
        candidates.sort((a, b) => {
          const aSynergy = cpuGpuSynergyScore(build.cpu, a);
          const bSynergy = cpuGpuSynergyScore(build.cpu, b);
          if (aSynergy !== bSynergy) return bSynergy - aSynergy;
          const aPrice = parseFloat(a.price);
          const bPrice = parseFloat(b.price);
          return Math.abs(aPrice - catBudget) - Math.abs(bPrice - catBudget);
        });
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
      const moboFormFactor = String(build.motherboard.form_factor || "").toUpperCase();
      candidates = candidates.filter(c => {
        const caseType = String(c.type || "").toUpperCase();
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
        candidates = filterCasesByColor(candidates, color);
      }
      if (caseStyle !== "any") {
        const styled = filterCasesByStyle(candidates, caseStyle);
        if (styled.length > 0) candidates = styled;
      }
      if (caseStyle === "any") {
        const defaultNames = ["lian li vector", "phanteks xt m3", "antec ax20", "antec ax27"];
        const preferred = candidates.filter(c => {
          const name = String(c.name || "").toLowerCase();
          return defaultNames.some(dn => name.includes(dn));
        });
        if (preferred.length > 0) {
          const best = preferred.sort((a, b) => {
            const aPrice = parseFloat(a.price);
            const bPrice = parseFloat(b.price);
            const aOver = aPrice > catBudget ? 1 : 0;
            const bOver = bPrice > catBudget ? 1 : 0;
            if (aOver !== bOver) return aOver - bOver;
            return Math.abs(aPrice - catBudget) - Math.abs(bPrice - catBudget);
          })[0];
          if (parseFloat(best.price) <= catBudget * 1.15) {
            build[cat.id] = best;
            continue;
          }
        }
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
        const filtered = candidates.filter(m => {
          const vertRes = parseInt(m.refresh_rate) || 0;
          return vertRes >= targetVerticalRes || (parseInt(m.resolution) || 0) >= 2560;
        });
        if (filtered.length > 0) candidates = filtered;
      }
    }

    if (cat.id !== "ssd" || !dualStorage || !build.ssd) {
      candidates.sort((a, b) => {
        const aPrice = parseFloat(a.price);
        const bPrice = parseFloat(b.price);
        const aOver = aPrice > catBudget ? 1 : 0;
        const bOver = bPrice > catBudget ? 1 : 0;
        if (aOver !== bOver) return aOver - bOver;
        const aRel = scoreComponentReliability(cat.id, a);
        const bRel = scoreComponentReliability(cat.id, b);
        if (aRel !== bRel) return bRel - aRel;
        let aScore = 0;
        let bScore = 0;
        if (cat.id === "cpu") {
          aScore = scoreCpuForWorkload(a, workload, budget);
          bScore = scoreCpuForWorkload(b, workload, budget);
        } else if (cat.id === "gpu") {
          aScore = scoreGpuForWorkload(a, workload, budget);
          bScore = scoreGpuForWorkload(b, workload, budget);
        } else if (cat.id === "ram") {
          aScore = scoreRamForWorkload(a, workload, budget);
          bScore = scoreRamForWorkload(b, workload, budget);
        } else if (cat.id === "ssd") {
          aScore = scoreStorageForWorkload(a, workload, budget);
          bScore = scoreStorageForWorkload(b, workload, budget);
        } else if (cat.id === "psu") {
          aScore = scorePsuReliability(a) / 3;
          bScore = scorePsuReliability(b) / 3;
        }
        if (aScore !== bScore) return bScore - aScore;
        return Math.abs(aPrice - catBudget) - Math.abs(bPrice - catBudget);
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
      if (catId === "cpu") {
        if (consumerOnly) {
          candidates = candidates.filter(c => {
            const name = (c.name || "").toUpperCase();
            if (name.includes("THREADRIPPER") || name.includes("XEON") || name.includes("EPYC")) return false;
            const socket = inferCpuSocket(c);
            if (socket && (socket === "LGA1151" || socket === "LGA1150" || socket === "LGA1155" || socket === "LGA775" || socket === "LGA1200")) return false;
            return true;
          });
        }
        candidates = candidates.filter(c => isWindows11Compatible(c) && isModernComponent("cpu", c));
      }
      if (catId === "ram" && build.motherboard) {
        const moboGen = (() => {
          const t = String(build.motherboard.ram_type || "").toLowerCase();
          const n = String(build.motherboard.name || "").toUpperCase();
          const s = String(build.motherboard.socket || "").toUpperCase();
          if (t.includes("ddr5") || n.includes("DDR5") || s === "AM5" || s === "LGA1851") return "DDR5";
          if (t.includes("ddr4") || n.includes("DDR4") || n.includes(" D4")) return "DDR4";
          return null;
        })();
        if (moboGen === "DDR5") {
          candidates = candidates.filter(c => isDdr5(c));
        } else if (moboGen === "DDR4") {
          candidates = candidates.filter(c => !isDdr5(c));
        }
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

  {
    let total = Object.values(build).reduce((s, item) => s + (parseFloat(item.price) || 0), 0);
    if (total > budget) {
      const catsByPrice = Object.entries(build)
        .filter(([, item]) => item && typeof item === "object" && item.price !== undefined)
        .sort(([, a], [, b]) => parseFloat(b.price) - parseFloat(a.price));
      for (const [catId, currentItem] of catsByPrice) {
        if (Object.values(build).reduce((s, item) => s + (parseFloat(item.price) || 0), 0) <= budget) break;
        if (!allParts[catId]) continue;
        const currentPrice = parseFloat(currentItem.price) || 0;
        const cheapest = [...allParts[catId]].sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
        if (cheapest.length > 0) {
          const cheapestPrice = parseFloat(cheapest[0].price) || 0;
          if (cheapestPrice < currentPrice) {
            build[catId] = cheapest[0];
          }
        }
      }
    }
  }

  if (!build["thermal-paste"] && allParts["thermal-paste"]) {
    const paste = [...allParts["thermal-paste"]].sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
    if (paste.length > 0) build["thermal-paste"] = paste[0];
  }

  if (needWifi && !build["wireless-network-card"] && build.motherboard) {
    const moboName = String(build.motherboard.name || "").toLowerCase();
    const hasWifi = moboName.includes("wifi") || moboName.includes("wi-fi") || moboName.includes("wireless");
    if (!hasWifi && allParts["wireless-network-card"]) {
      const cards = [...allParts["wireless-network-card"]].sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
      if (cards.length > 0) build["wireless-network-card"] = cards[0];
    }
  }

  return build;
}

export async function generateRecommendedBuild(budget, useCase, color = "any", options = {}) {
  const recBudget = Math.max(budget * 2, 2500);
  const opts = { ...options, dualStorage: true };
  return generateBuild(recBudget, useCase, color, opts);
}

export function explainBuild(build, label, useCase, budget, hiddenFees = 0) {
  const cpu = build.cpu?.name || "a capable CPU";
  const gpu = build.gpu?.name || "integrated graphics";
  const ram = build.ram?.name || "RAM";
  const ramSize = parseRamGB(ram);
  const ramDdr = getRamDdr(build.ram);
  const partsTotal = Object.values(build).reduce((s, item) => s + (parseFloat(item.price) || 0), 0);
  const total = partsTotal + hiddenFees;
  const wl = classifyWorkload(useCase);
  const isGaming = wl.isGaming;
  const isStream = wl.isStreaming;
  const isWork = wl.isWorkstation || wl.isContentCreation;
  const isMl = wl.isMachineLearning;

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

  if (isMl) {
    const vram = parseFloat(build.gpu?.memory) || 0;
    if (vram >= 12) {
      parts.push(`For machine learning workloads, the ${vram}GB VRAM on the GPU provides enough memory for training models locally. The ${ramSize}GB system RAM helps with data preprocessing.`);
    } else {
      parts.push(`This build handles entry-level ML inference and small model training. For larger models, a GPU with more VRAM (16GB+) would be recommended.`);
    }
  }

  if (build.ssd) {
    const mainDrive = build.ssd.name || "";
    if (build["mass-storage"]) {
      parts.push(`Storage combines a fast ${mainDrive} for your operating system and favourite apps, plus a ${build["mass-storage"].name || "large hard drive"} for mass storage — best of both worlds.`);
    } else {
      parts.push(`Storage: ${mainDrive}.`);
    }
  }

  if (build["wireless-network-card"]) {
    const moboName = String(build.motherboard?.name || "").toLowerCase();
    const moboHasWifi = moboName.includes("wifi") || moboName.includes("wi-fi");
    if (moboHasWifi) {
      parts.push(`This motherboard has built-in WiFi, so no separate adapter is needed.`);
    } else {
      parts.push(`A WiFi adapter is included since the motherboard doesn't have built-in wireless. If you prefer a WiFi motherboard instead, we can swap it in.`);
    }
  }

  if (build.psu) {
    const psuRelScore = scorePsuReliability(build.psu);
    if (psuRelScore >= 10) {
      parts.push(`The power supply is a reliable, well-rated unit that will deliver clean, stable power for years.`);
    } else if (psuRelScore < 5) {
      parts.push(`The power supply is functional but from a less proven brand. If reliability is a priority, upgrading to a Gold-rated unit from Seasonic, Corsair, or be quiet! is worthwhile.`);
    }
  }

  if (isGaming && build.cpu && build.gpu) {
    const cpuTier = getCpuTier(build.cpu);
    const gpuTier = getGpuTier(build.gpu);
    const diff = cpuTier - gpuTier;
    if (diff >= 2) {
      parts.push(`Note: The CPU is significantly more powerful than the GPU. You may see better value by allocating more budget to the graphics card for gaming.`);
    } else if (diff <= -2) {
      parts.push(`Note: The GPU is significantly more powerful than the CPU. In some games, the CPU may limit your frame rates before the GPU reaches full potential.`);
    } else {
      parts.push(`The CPU and GPU are well-matched — neither component will bottleneck the other.`);
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

  if (isGaming && build.gpu) {
    const vram = parseFloat(build.gpu.memory) || 0;
    if (budget < 800) {
      parts.push(`At this budget, smooth 1080p gaming is the realistic target. The GPU in this build handles most titles well at medium-to-high settings. Pushing to 1440p or 4K would require a significantly more expensive graphics card.`);
    } else if (budget < 1200) {
      parts.push(`This budget targets solid 1080p performance with headroom for 1440p in lighter titles. For consistent high-refresh 1440p gaming across all games, a larger GPU investment would be needed.`);
    } else if (budget < 2000) {
      if (vram < 10) {
        parts.push(`At 1440p, this GPU will handle most games well, but some demanding titles may need settings adjustments. For native 4K, a GPU with more VRAM (12GB+) would be a worthwhile upgrade.`);
      }
    } else if (vram < 12) {
      parts.push(`With this budget, 4K is within reach, but this GPU's ${vram}GB VRAM may be limiting at ultra settings in the most demanding titles. Consider stepping up to a 12GB+ card for a smoother 4K experience.`);
    }
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
  const igpu = String(cpu?.graphics || "").toLowerCase();
  return igpu === "none" || igpu === "";
}

function allocateBudget(totalBudget, useCase) {
  const gamingAlloc = { cpu: 0.18, gpu: 0.25, motherboard: 0.09, cooler: 0.04, "case": 0.04, ram: 0.08, ssd: 0.04, "case-fan": 0.01, "mass-storage": 0.02, psu: 0.05, os: 0.08, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.01, webcam: 0.01, "wireless-network-card": 0.01, "thermal-paste": 0.005, "fan-controller": 0.005 };
  const streamingAlloc = { cpu: 0.20, gpu: 0.23, motherboard: 0.09, cooler: 0.05, "case": 0.04, ram: 0.08, ssd: 0.05, "case-fan": 0.01, "mass-storage": 0.02, psu: 0.04, os: 0.08, monitor: 0.03, headphones: 0.02, keyboard: 0.01, mouse: 0.01, speakers: 0.01, webcam: 0.01, "wireless-network-card": 0.01, "thermal-paste": 0.005, "fan-controller": 0.005 };
  const workstationAlloc = { cpu: 0.23, gpu: 0.19, motherboard: 0.09, cooler: 0.06, "case": 0.04, ram: 0.08, ssd: 0.05, "case-fan": 0.01, "mass-storage": 0.03, psu: 0.04, os: 0.08, monitor: 0.04, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.0, webcam: 0.01, "wireless-network-card": 0.01, "thermal-paste": 0.005, "fan-controller": 0.005 };
  const contentCreationAlloc = { cpu: 0.21, gpu: 0.19, motherboard: 0.08, cooler: 0.05, "case": 0.04, ram: 0.09, ssd: 0.07, "case-fan": 0.01, "mass-storage": 0.03, psu: 0.04, os: 0.08, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.0, webcam: 0.01, "wireless-network-card": 0.01, "thermal-paste": 0.005, "fan-controller": 0.005 };
  const machineLearningAlloc = { cpu: 0.20, gpu: 0.30, motherboard: 0.08, cooler: 0.05, "case": 0.03, ram: 0.10, ssd: 0.08, "case-fan": 0.01, "mass-storage": 0.02, psu: 0.06, os: 0.04, monitor: 0.01, headphones: 0.0, keyboard: 0.0, mouse: 0.0, speakers: 0.0, webcam: 0.0, "wireless-network-card": 0.01, "thermal-paste": 0.005, "fan-controller": 0.005 };
  const generalAlloc = { cpu: 0.18, gpu: 0.19, motherboard: 0.10, cooler: 0.05, "case": 0.04, ram: 0.09, ssd: 0.05, "case-fan": 0.01, "mass-storage": 0.02, psu: 0.06, os: 0.08, monitor: 0.05, headphones: 0.01, keyboard: 0.01, mouse: 0.01, speakers: 0.01, webcam: 0.01, "wireless-network-card": 0.01, "thermal-paste": 0.005, "fan-controller": 0.005 };

  const wl = classifyWorkload(useCase);
  let alloc = generalAlloc;
  if (wl.isGaming && wl.isStreaming) alloc = streamingAlloc;
  else if (wl.isMachineLearning) alloc = machineLearningAlloc;
  else if (wl.isGaming) alloc = gamingAlloc;
  else if (wl.isContentCreation) alloc = contentCreationAlloc;
  else if (wl.isStreaming) alloc = streamingAlloc;
  else if (wl.isWorkstation) alloc = workstationAlloc;

  const allocation = {};
  for (const [cat, pct] of Object.entries(alloc)) {
    allocation[cat] = totalBudget * pct;
  }
  return allocation;
}

function filterCpuForUseCase(cpus, useCase, monitorResolution) {
  const use = (useCase || "").toLowerCase();
  const isGaming = use.includes("gaming");
  const isWorkstation = use.includes("workstation") || use.includes("render") || use.includes("video") || use.includes("creation");
  const isStreaming = use.includes("stream");

  return cpus.filter(cpu => {
    if (!isModernComponent("cpu", cpu)) return false;
    if (!isWindows11Compatible(cpu)) return false;

    const cores = parseFloat(cpu.core_count) || 0;
    if (isWorkstation && cores < 6) return false;
    if (isStreaming && cores < 6) return false;
    if (isGaming && cores < 4) return false;

    if (isGaming && monitorResolution === "1080p") {
      const name = (cpu.name || "").toUpperCase();
      if (name.includes("9800X3D") || name.includes("9950X3D") || name.includes("9900X3D") || name.includes("9900X")) return false;
      if (name.includes("7950X3D") || name.includes("7900X3D") || name.includes("7600X3D")) return false;
      if (name.includes("ULTRA 9") || name.includes("CORE I-9")) return false;
    }

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
    if (vram < 6) return false;
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
  if (build.ssd) total += 10;
  if (build["mass-storage"]) total += 10;
  if (build.cooler) total += parseFloat(build.cooler.wattage) || 10;
  return total + 150;
}

function parseRadiatorMm(cooler) {
  const raw = String(cooler.radiator_size || cooler.size || "");
  if (!raw || raw.toLowerCase().includes("radiator size")) return 0;
  const m = raw.match(/(\d+)\s*mm/i);
  return m ? parseInt(m[1], 10) : 0;
}

function classifyWorkload(useCase) {
  const u = (useCase || "").toLowerCase();
  const wl = {
    isGaming: false,
    isStreaming: false,
    isContentCreation: false,
    isWorkstation: false,
    isMachineLearning: false,
    isGeneral: false,
  };
  if (u.includes("gaming")) wl.isGaming = true;
  if (u.includes("stream")) wl.isStreaming = true;
  if (u.includes("creation") || u.includes("content")) wl.isContentCreation = true;
  if (u.includes("workstation") || u.includes("cad") || u.includes("3d") || u.includes("render") || u.includes("video editing")) wl.isWorkstation = true;
  if (u.includes("machine learning") || u.includes("ml") || u.includes("ai") || u.includes("deep learning")) wl.isMachineLearning = true;
  if (u.includes("general") || u.includes("office") || u.includes("home") || u.includes("student")) wl.isGeneral = true;
  if (!Object.values(wl).some(v => v)) wl.isGeneral = true;
  return wl;
}

const CPU_TIER = {
  "ryzen 5 9600x": 3, "ryzen 7 9700x": 4, "ryzen 9 9900x": 5, "ryzen 9 9950x": 6,
  "ryzen 5 7600x": 3, "ryzen 7 7700x": 4, "ryzen 9 7900x": 5, "ryzen 9 7950x": 6,
  "core i5-14600k": 3, "core i7-14700k": 4, "core i9-14900k": 6,
  "core i5-13600k": 3, "core i7-13700k": 4, "core i9-13900k": 6,
  "core i5-12600k": 3, "core i7-12700k": 4, "core i9-12900k": 5,
  "ryzen 5 5600x": 3, "ryzen 7 5800x": 4, "ryzen 9 5900x": 5, "ryzen 9 5950x": 6,
  "ultra 5": 3, "ultra 7": 4, "ultra 9": 6,
  "core i5": 3, "core i7": 4, "core i9": 6,
  "ryzen 5": 3, "ryzen 7": 4, "ryzen 9": 6,
  "pentium": 1, "celeron": 1, "athlon": 2,
};

const GPU_TIER = {
  "rtx 5090": 6, "rtx 5080": 5, "rtx 5070 ti": 5, "rtx 5070": 4,
  "rtx 4090": 6, "rtx 4080": 5, "rtx 4070 ti super": 5, "rtx 4070 super": 4, "rtx 4070": 4, "rtx 4060 ti": 3, "rtx 4060": 3,
  "rx 7900 xtx": 6, "rx 7900 xt": 5, "rx 7800 xt": 4, "rx 7700 xt": 4, "rx 7600 xt": 3, "rx 7600": 3,
  "arc b580": 3, "arc b570": 3, "arc a770": 4, "arc a750": 3, "arc a580": 3,
  "gtx 1660": 2, "gtx 1650": 2, "gtx 1080": 3, "gtx 1070": 2,
  "rx 6600": 3, "rx 6650 xt": 3, "rx 6700 xt": 3, "rx 6800": 4, "rx 6900 xt": 5,
};

function getCpuTier(cpu) {
  const name = String(cpu.name || "").toLowerCase();
  for (const [key, tier] of Object.entries(CPU_TIER)) {
    if (name.includes(key)) return tier;
  }
  const cores = parseFloat(cpu.core_count) || 0;
  if (cores >= 16) return 6;
  if (cores >= 12) return 5;
  if (cores >= 8) return 4;
  if (cores >= 6) return 3;
  if (cores >= 4) return 2;
  return 1;
}

function getGpuTier(gpu) {
  const name = String(gpu.name || "").toLowerCase();
  for (const [key, tier] of Object.entries(GPU_TIER)) {
    if (name.includes(key)) return tier;
  }
  const vram = parseFloat(gpu.memory) || 0;
  if (vram >= 16) return 5;
  if (vram >= 12) return 4;
  if (vram >= 8) return 3;
  if (vram >= 6) return 2;
  return 1;
}

function cpuGpuSynergyScore(cpu, gpu) {
  const cpuTier = getCpuTier(cpu);
  const gpuTier = getGpuTier(gpu);
  const diff = Math.abs(cpuTier - gpuTier);
  if (diff === 0) return 1.0;
  if (diff === 1) return 0.8;
  if (diff === 2) return 0.5;
  return 0.2;
}

const PSU_RELIABILITY_BRANDS = [
  { pattern: /seasonic/i, tier: 1 },
  { pattern: /super\s*flower/i, tier: 1 },
  { pattern: /corsair.*(?:rm[xi]?|hx[xi]?|ax[xi]?|tx[mi]?|sf)/i, tier: 1 },
  { pattern: /corsair/i, tier: 2 },
  { pattern: /evga.*(?:g[2-9]|p[2-6]|t[2-6]|b[2-6]|super\s*nova)/i, tier: 1 },
  { pattern: /evga/i, tier: 2 },
  { pattern: /be\s*quiet/i, tier: 1 },
  { pattern: /thermaltake.*toughpower/i, tier: 1 },
  { pattern: /thermaltake/i, tier: 2 },
  { pattern: /silverstone/i, tier: 1 },
  { pattern: /cooler\s*master.*(?:v\s*\d|master\s*power)/i, tier: 1 },
  { pattern: /cooler\s*master/i, tier: 2 },
  { pattern: /fractal\s*design/i, tier: 1 },
  { pattern: /deepcool/i, tier: 2 },
  { pattern: /mushkin/i, tier: 2 },
  { pattern: /xpg/i, tier: 2 },
  { pattern: /msi.*(?:mag|meg)/i, tier: 1 },
  { pattern: /msi/i, tier: 2 },
  { pattern: /nzxt/i, tier: 2 },
  { pattern: /lian\s*li/i, tier: 2 },
  { pattern: /aerocool/i, tier: 3 },
  { pattern: /gigabyte.*(?:aorus|ud|gp)/i, tier: 2 },
  { pattern: /gigabyte/i, tier: 3 },
  { pattern: /power\s*man/i, tier: 3 },
  { pattern: /deer/i, tier: 3 },
  { pattern: /hec/i, tier: 3 },
  { pattern: /diablotek/i, tier: 3 },
  { pattern: /arena/i, tier: 3 },
];

function scorePsuReliability(psu) {
  const name = String(psu.name || "");
  let brandScore = 2;
  for (const { pattern, tier } of PSU_RELIABILITY_BRANDS) {
    if (pattern.test(name)) { brandScore = tier; break; }
  }
  let ratingMult = 1.0;
  const lowerName = name.toLowerCase();
  if (/80\s*\+\s*titanium/i.test(lowerName)) ratingMult = 1.3;
  else if (/80\s*\+\s*platinum/i.test(lowerName)) ratingMult = 1.2;
  else if (/80\s*\+\s*gold/i.test(lowerName)) ratingMult = 1.1;
  else if (/80\s*\+\s*bronze/i.test(lowerName)) ratingMult = 1.0;
  else if (/80\s*\+\s*(?:white|standard)/i.test(lowerName)) ratingMult = 0.9;
  const base = brandScore === 1 ? 10 : brandScore === 2 ? 7 : 4;
  return base * ratingMult;
}

const COMPONENT_RELIABILITY = {
  cpu: { boost: ["amd.*ryzen.*9", "amd.*ryzen.*7", "intel.*core.*i7", "intel.*core.*i9"], penalty: ["intel.*pentium", "intel.*celeron"] },
  gpu: { boost: ["nvidia.*rtx.*[45]0[789]", "amd.*rx.*7[89]00", "intel.*arc.*b"], penalty: ["nvidia.*gt[71]0", "amd.*rx.*5[05]0"] },
  ram: { boost: ["corsair.*vengeance", "g\.skill.*trident", "kingston.*fury.*beast", "teamgroup.*t-force"], penalty: [] },
  storage: { boost: ["samsung.*9[89]0", "samsung.*970", "wd.*black.*sn[89]", "crucial.*t[57]00"], penalty: ["kingston.*a400", "teamgroup.*cx2"] },
  motherboard: { boost: ["asus.* rog", "msi.*meg", "gigabyte.*aorus", "asrock.*taichi"], penalty: ["gigabyte.*b[46]50.*d3hp", "asrock.*b[46]50.*hdv"] },
  cooler: { boost: ["noctua", "arctic.*liquid.*freezer", "be\s*quiet.*dark.*rock", "corsair.*icue.*h1[57]0"], penalty: [] },
};

function scoreComponentReliability(category, part) {
  const rules = COMPONENT_RELIABILITY[category];
  if (!rules) return 0;
  const name = String(part.name || "");
  for (const pat of rules.boost) {
    if (new RegExp(pat, "i").test(name)) return 3;
  }
  for (const pat of rules.penalty) {
    if (new RegExp(pat, "i").test(name)) return -2;
  }
  return 0;
}

function scoreCpuForWorkload(cpu, workload, budget) {
  let score = 0;
  const cores = parseFloat(cpu.core_count) || 0;
  const boost = parseFloat(cpu.boost_clock) || 0;
  const tdp = parseFloat(cpu.tdp) || 65;
  if (workload.isGaming) {
    if (boost >= 5.0) score += 5;
    else if (boost >= 4.7) score += 4;
    else if (boost >= 4.5) score += 3;
    if (cores >= 6 && cores <= 8) score += 3;
    else if (cores >= 12) score += 1;
  }
  if (workload.isStreaming) {
    if (cores >= 8) score += 4;
    else if (cores >= 6) score += 2;
  }
  if (workload.isContentCreation || workload.isWorkstation) {
    if (cores >= 16) score += 5;
    else if (cores >= 12) score += 4;
    else if (cores >= 8) score += 2;
    const name = (cpu.name || "").toLowerCase();
    if (name.includes("x3d")) score -= 1;
  }
  if (workload.isMachineLearning) {
    if (cores >= 12) score += 4;
    else if (cores >= 8) score += 2;
    if (tdp <= 125) score += 1;
  }
  return score;
}

function scoreGpuForWorkload(gpu, workload, budget) {
  let score = 0;
  const vram = parseFloat(gpu.memory) || 0;
  const name = String(gpu.name || "").toLowerCase();
  if (workload.isGaming) {
    if (vram >= 12) score += 4;
    else if (vram >= 8) score += 3;
    else if (vram >= 6) score += 1;
    if (name.includes("rtx")) score += 2;
    if (name.includes("rx 7")) score += 1;
  }
  if (workload.isStreaming) {
    if (name.includes("nvenc") || name.includes("rtx")) score += 3;
    if (vram >= 8) score += 1;
  }
  if (workload.isContentCreation || workload.isWorkstation) {
    if (vram >= 16) score += 5;
    else if (vram >= 12) score += 4;
    else if (vram >= 8) score += 2;
    if (name.includes("rtx")) score += 2;
    if (name.includes("quadro") || name.includes("rtx a")) score += 3;
  }
  if (workload.isMachineLearning) {
    if (vram >= 16) score += 5;
    else if (vram >= 12) score += 4;
    else if (vram >= 8) score += 2;
    if (name.includes("rtx")) score += 3;
  }
  return score;
}

function scoreRamForWorkload(ram, workload, budget) {
  let score = 0;
  const totalGB = getRamCapacityGB(ram);
  if (workload.isGaming) {
    if (totalGB >= 32) score += 3;
    else if (totalGB >= 16) score += 2;
  }
  if (workload.isStreaming || workload.isContentCreation || workload.isWorkstation) {
    if (totalGB >= 64) score += 5;
    else if (totalGB >= 32) score += 3;
  }
  if (workload.isMachineLearning) {
    if (totalGB >= 64) score += 5;
    else if (totalGB >= 32) score += 3;
  }
  return score;
}

function scoreStorageForWorkload(storage, workload, budget) {
  let score = 0;
  const capacityGB = (parseFloat(storage.capacity) || 0) * 1000;
  const isSsdItem = isSsd(storage);
  if (workload.isGaming) {
    if (capacityGB >= 2000 && isSsdItem) score += 4;
    else if (capacityGB >= 1000 && isSsdItem) score += 3;
    else if (capacityGB >= 500 && isSsdItem) score += 2;
  }
  if (workload.isContentCreation || workload.isWorkstation) {
    if (capacityGB >= 4000 && isSsdItem) score += 5;
    else if (capacityGB >= 2000 && isSsdItem) score += 3;
  }
  if (workload.isMachineLearning) {
    if (capacityGB >= 4000 && isSsdItem) score += 5;
    else if (capacityGB >= 2000 && isSsdItem) score += 3;
  }
  return score;
}
