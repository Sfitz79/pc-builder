import { toNumber as commonToNumber, normalizeToken as commonNormalizeToken, inferCpuSocket as commonInferCpuSocket, inferCoolerType as commonInferCoolerType, isWindows11Compatible } from "./common.js";

export function checkCompatibility(selections) {
  const issues = [];
  
  const cpu = selections.cpu;
  const mobo = selections.motherboard;
  const ram = selections.ram;
  const gpu = selections.gpu;
  const psu = selections.psu;
  const caseObj = selections.case;
  const cooler = selections.cooler;

  const REQUIRED_IDS = ["case", "case-fan", "cooler", "cpu", "motherboard", "ram", "storage", "psu", "os"];
  for (const id of REQUIRED_IDS) {
    if (!selections[id]) {
      const label = id.charAt(0).toUpperCase() + id.slice(1);
      issues.push(`${label} is required to complete the build.`);
    }
  }
  if (!gpu) {
    const hasIgpu = cpu && cpu.integrated_graphics && String(cpu.integrated_graphics).toLowerCase() !== "none";
    if (!hasIgpu) {
      issues.push("GPU is required (selected CPU has no integrated graphics).");
    }
  }
  
  if (cpu && mobo && !isMotherboardCompatibleWithCpu(mobo, cpu)) {
    issues.push("CPU and motherboard socket do not match.");
  }

  if (cpu && !isWindows11Compatible(cpu)) {
    issues.push("Selected CPU is not compatible with Windows 11.");
  }

  if (mobo && ram && !isRamCompatibleWithMotherboard(ram, mobo)) {
    issues.push("Motherboard RAM type and selected RAM type differ.");
  }
  
  if (gpu && psu && !isPsuCompatibleWithBuild(psu, gpu, cpu)) {
    issues.push("PSU wattage may be too low for this GPU.");
  }
  
  if (mobo && caseObj && !isMotherboardCompatibleWithCase(mobo, caseObj)) {
    issues.push("Motherboard form factor is larger than supported by the selected case.");
  }
  
  if (caseObj && cooler && !isCaseCompatibleWithCooler(caseObj, cooler)) {
    issues.push("Selected CPU cooler may not fit in this case.");
  }
  
  return issues;
}

export function isOptionCompatible(categoryId, option, selections) {
  switch (categoryId) {
    case "cpu":
      return isCpuCompatibleWithMotherboard(option, selections.motherboard);
    case "motherboard":
      return (
        isMotherboardCompatibleWithCpu(option, selections.cpu) &&
        isMotherboardCompatibleWithRam(option, selections.ram) &&
        isMotherboardCompatibleWithCase(option, selections.case)
      );
    case "ram":
      return isRamCompatibleWithMotherboard(option, selections.motherboard);
    case "gpu":
      return isGpuCompatibleWithPsu(option, selections.psu, selections.cpu);
    case "psu":
      return isPsuCompatibleWithBuild(option, selections.gpu, selections.cpu);
    case "case":
      return isCaseCompatibleWithMotherboard(option, selections.motherboard) && 
             isCaseCompatibleWithCooler(option, selections.cooler);
    case "cooler":
      return isCoolerCompatibleWithCase(option, selections.case);
    default:
      return true;
  }
}

function isCaseCompatibleWithCooler(caseObj, cooler) {
  if (!caseObj || !cooler) return true;
  
  const caseType = normalizeToken(caseObj.type);
  const coolerSize = normalizeToken(cooler.size);
  
  if (!caseType || !coolerSize) return true;
  
  const coolerNum = parseInt(coolerSize);
  if (isNaN(coolerNum)) return true;
  
  const caseLower = caseType.toLowerCase();
  
  // Full towers support all sizes
  if (caseLower.includes("full")) return true;
  
  // Mid towers support up to 360mm
  if (caseLower.includes("mid") || caseLower.includes("atx")) {
    return coolerNum <= 360;
  }
  
  // Micro-ATX/Mini-ITX support up to 240mm
  if (caseLower.includes("micro") || caseLower.includes("mini") || caseLower.includes("itx")) {
    return coolerNum <= 240;
  }
  
  return true;
}

function isCoolerCompatibleWithCase(cooler, caseObj) {
  return isCaseCompatibleWithCooler(caseObj, cooler);
}

function isMotherboardCompatibleWithCase(motherboard, caseObj) {
  if (!motherboard || !caseObj) {
    return true;
  }
  
  const moboFactor = normalizeToken(motherboard.form_factor);
  const caseType = normalizeToken(caseObj.type);
  
  if (!moboFactor || !caseType) {
    return true;
  }
  
  const normalizeFactor = (f) => {
    if (f.includes("EATX")) return "EATX";
    if (f.includes("MICRO ATX") || f.includes("MICRO-ATX") || f.includes("MATX")) return "MATX";
    if (f.includes("MINI ITX") || f.includes("MINI-ITX") || f.includes("MITX")) return "MITX";
    if (f.includes("ATX")) return "ATX";
    return f;
  };
  
  const normMobo = normalizeFactor(moboFactor);
  const normCase = normalizeFactor(caseType);
  
  const sizeMap = { EATX: 4, ATX: 3, MATX: 2, MITX: 1 };
   
  if (sizeMap[normMobo] && sizeMap[normCase]) {
    return sizeMap[normMobo] <= sizeMap[normCase];
  }
  
  return true;
}

function isCaseCompatibleWithMotherboard(caseObj, motherboard) {
  return isMotherboardCompatibleWithCase(motherboard, caseObj);
}

function isCpuCompatibleWithMotherboard(cpu, motherboard) {
  if (!cpu || !motherboard) {
    return true;
  }
  
  const cpuSocket = inferCpuSocket(cpu);
  const moboSocket = normalizeToken(motherboard.socket);
  
  if (!cpuSocket || !moboSocket) {
    return true;
  }
  
  return cpuSocket === moboSocket;
}

function isMotherboardCompatibleWithCpu(motherboard, cpu) {
  return isCpuCompatibleWithMotherboard(cpu, motherboard);
}

function isMotherboardCompatibleWithRam(motherboard, ram) {
  if (!motherboard || !ram) {
    return true;
  }
  return isRamCompatibleWithMotherboard(ram, motherboard);
}

function isRamCompatibleWithMotherboard(ram, motherboard) {
  if (!ram || !motherboard) {
    return true;
  }
  
  const maxMemory = toNumber(motherboard.max_memory);
  const ramCapacity = parseRamCapacity(ram.modules);
  if (maxMemory > 0 && ramCapacity > 0 && ramCapacity > maxMemory) {
    return false;
  }
  
  const ramGen = inferRamGeneration(ram);
  const moboGen = inferMotherboardRamGeneration(motherboard);
  if (ramGen && moboGen && ramGen !== moboGen) {
    return false;
  }
  
  return true;
}

function isPsuCompatibleWithBuild(psu, gpu, cpu) {
  if (!psu || !gpu) {
    return true;
  }
  
  const psuWattage = toNumber(psu.wattage);
  const requiredWattage = estimateRequiredWattage(cpu, gpu);
  if (psuWattage <= 0 || requiredWattage <= 0) {
    return true;
  }
  
  return psuWattage >= requiredWattage;
}

function isGpuCompatibleWithPsu(gpu, psu, cpu) {
  return isPsuCompatibleWithBuild(psu, gpu, cpu);
}

export function estimateRequiredWattage(cpu, gpu) {
  const cpuTdp = estimateCpuTdp(cpu);
  const gpuTdp = estimateGpuTdp(gpu);
  
  if (gpuTdp <= 0) {
    return 0;
  }
  
  const systemHeadroom = 150;
  return cpuTdp + gpuTdp + systemHeadroom;
}

function estimateCpuTdp(cpu) {
  if (!cpu) {
    return 65;
  }
  const tdp = toNumber(cpu.tdp);
  return tdp > 0 ? tdp : 65;
}

function estimateGpuTdp(gpu) {
  if (!gpu) {
    return 0;
  }
  
  const direct = toNumber(gpu.tdp);
  if (direct > 0) {
    return direct;
  }
  
  const text = `${gpu.chipset ?? ""} ${gpu.name ?? ""}`.toLowerCase();
  
  const rtxMatch = text.match(/rtx\s*(\d{4})/);
  if (rtxMatch) {
    const tier = Number(rtxMatch[1]);
    if (tier >= 5090) return 575;
    if (tier >= 5080) return 360;
    if (tier >= 5070) return 250;
    if (tier >= 4090) return 450;
    if (tier >= 4080) return 320;
    if (tier >= 4070) return 220;
    if (tier >= 4060) return 130;
    if (tier >= 3090) return 350;
    if (tier >= 3080) return 320;
    if (tier >= 3070) return 220;
    if (tier >= 3060) return 170;
  }
  
  const rxMatch = text.match(/rx\s*(\d{4,5})/);
  if (rxMatch) {
    const tier = Number(rxMatch[1]);
    if (tier >= 9000) return 260;
    if (tier >= 7900) return 330;
    if (tier >= 7800) return 265;
    if (tier >= 7700) return 245;
    if (tier >= 7600) return 165;
    if (tier >= 6900) return 300;
    if (tier >= 6800) return 250;
    if (tier >= 6700) return 230;
    if (tier >= 6600) return 132;
  }
  
  const memory = toNumber(gpu.memory);
  if (memory >= 24) return 350;
  if (memory >= 16) return 280;
  if (memory >= 12) return 220;
  if (memory >= 8) return 170;
  return 120;
}

function inferCpuSocket(cpu) {
  return commonInferCpuSocket(cpu);
}

function inferRamGeneration(ram) {
  const explicit = commonNormalizeToken(ram?.type || ram?.ram_type);
  if (explicit?.includes("DDR")) {
    return explicit;
  }
  
  const speedStr = String(ram?.speed || "").trim();
  if (speedStr.includes(",")) {
    const parts = speedStr.split(",");
    const ddrPart = commonToNumber(parts[0]);
    if (ddrPart === 5) return "DDR5";
    if (ddrPart === 4) return "DDR4";
    if (ddrPart === 3) return "DDR3";
  }
  
  const speed = commonToNumber(ram?.speed);
  if (speed >= 4800) {
    return "DDR5";
  }
  if (speed >= 2133) {
    return "DDR4";
  }
  return null;
}

function inferMotherboardRamGeneration(motherboard) {
  const explicit = commonNormalizeToken(motherboard?.ram_type || motherboard?.memory_type);
  if (explicit?.includes("DDR")) {
    return explicit;
  }
  
  const name = String(motherboard?.name ?? "").toUpperCase();
  if (name.includes("DDR5")) return "DDR5";
  if (name.includes("DDR4") || name.includes(" D4")) return "DDR4";
  if (name.includes("DDR3") || name.includes(" D3")) return "DDR3";
  
  const socket = commonNormalizeToken(motherboard?.socket);
  if (socket === "AM5" || socket === "LGA1851") {
    return "DDR5";
  }
  if (socket === "AM4" || socket === "LGA1151") {
    return "DDR4";
  }
  if (socket === "LGA1150" || socket === "LGA1155" || socket === "LGA775") {
    return "DDR3";
  }
  return null;
}

function parseRamCapacity(modules) {
  if (typeof modules === "number") {
    return modules;
  }
  
  const raw = String(modules ?? "").trim();
  if (!raw) {
    return 0;
  }
  
  const parts = raw.split(",").map((part) => toNumber(part));
  if (parts.length >= 2 && parts[0] > 0 && parts[1] > 0) {
    return parts[0] * parts[1];
  }
  
  return toNumber(raw);
}

function toNumber(value) {
  return commonToNumber(value);
}

function normalizeToken(value) {
  return commonNormalizeToken(value);
}
