import { toNumber } from "./common.js";

export function getPerformanceScore(selections) {
  return getGamingPerformanceSummary(selections).score;
}

export function getGamingPerformanceSummary(selections) {
  const cpu = selections.cpu;
  const gpu = selections.gpu;
  const ram = selections.ram;
  const storage = selections.storage;

  const cpuScore = scoreCPU(cpu);
  const gpuScore = scoreGPU(gpu);
  const ramScore = scoreRAM(ram);
  const storageScore = scoreStorage(storage);

  const weighted =
    cpuScore * 0.25 +
    gpuScore * 0.55 +
    ramScore * 0.12 +
    storageScore * 0.08;

  const score = clamp(Math.round(weighted), 0, 100);
  const cpuGpuBalance = Math.min(cpuScore, gpuScore) / Math.max(cpuScore, gpuScore, 1);
  const balanceFactor = 0.9 + cpuGpuBalance * 0.2;
  const fps1080p = estimateFps(score, balanceFactor);

  const resolutionCapabilities = getResolutionCapabilities(gpuScore);
  const optimalResolution = resolutionCapabilities[resolutionCapabilities.length - 1] || "1080p";
  const qualityPreset = getQualityPreset(score);
  const gamingGrade = getGamingGrade(score, selections);
  const performanceSummary = getPerformanceSummary(selections, score, optimalResolution, qualityPreset);

  const gamePerformance = getGamePerformance(score, optimalResolution, gpu);
  const allResPerformance = {
    "1080p": getGamePerformance(score, "1080p", gpu),
    "1440p": resolutionCapabilities.includes("1440p") ? getGamePerformance(score, "1440p", gpu) : null,
    "4K": resolutionCapabilities.includes("4K") ? getGamePerformance(score, "4K", gpu) : null,
  };

  return {
    score,
    fps1080p,
    qualityPreset,
    gamingGrade,
    resolutionCapabilities,
    optimalResolution,
    performanceSummary,
    gamePerformance,
    allResPerformance
  };
}

function scoreCPU(cpu) {
  if (!cpu) {
    return 0;
  }

  const cores = toNumber(cpu.core_count);
  const baseClock = toNumber(cpu.core_clock);
  const boostClock = toNumber(cpu.boost_clock);

  const coresScore = normalize(cores, 4, 16, 50);
  const clockScore = normalize(boostClock || baseClock, 3, 5.5, 50);

  return coresScore + clockScore;
}

function scoreGPU(gpu) {
  if (!gpu) {
    return 0;
  }

  const memory = toNumber(gpu.memory);
  const boostClock = toNumber(gpu.boost_clock);

  const memoryScore = normalize(memory, 4, 16, 75); // Increased weight for memory
  const clockScore = normalize(boostClock, 1200, 2600, 25);

  return memoryScore + clockScore;
}

function scoreRAM(ram) {
  if (!ram) {
    return 0;
  }

  const speed = toNumber(ram.speed);
  const modules = toNumber(ram.modules);
  const latency = toNumber(ram.cas_latency);

  const speedScore = normalize(speed, 2400, 7600, 55);
  const capacityScore = normalize(modules, 8, 64, 35);
  const latencyScore = normalizeInverted(latency, 40, 28, 10);

  return speedScore + capacityScore + latencyScore;
}

function scoreStorage(storage) {
  if (!storage) {
    return 0;
  }

  const capacity = toNumber(storage.capacity);
  const type = String(storage.type ?? "").toLowerCase();
  const isNvme = type.includes("nvme") || type.includes("m.2");
  const isSsd = isNvme || type.includes("ssd");

  const capacityScore = normalize(capacity, 256, 4000, 55);
  const typeScore = isNvme ? 45 : isSsd ? 30 : 12;

  return capacityScore + typeScore;
}


function normalize(value, min, max, weight) {
  if (value <= 0 || max <= min) {
    return 0;
  }
  const ratio = clamp((value - min) / (max - min), 0, 1.2); // Allow up to 20% bonus for exceeding typical max
  return ratio * weight;
}

function normalizeInverted(value, worst, best, weight) {
  if (value <= 0 || worst <= best) {
    return 0;
  }
  const ratio = clamp((worst - value) / (worst - best), 0, 1);
  return ratio * weight;
}

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

function estimateFps(score, balanceFactor) {
  // Maps score to typical 1080p gaming fps for mixed AAA/esports loads.
  const baseFps = 25 + score * 1.45;
  const adjustedFps = baseFps * balanceFactor;
  return Math.round(clamp(adjustedFps, 20, 240));
}

function getQualityPreset(score) {
  if (score >= 90) {
    return "Ultra";
  }
  if (score >= 75) {
    return "High";
  }
  if (score >= 60) {
    return "Medium-High";
  }
  if (score >= 45) {
    return "Medium";
  }
  return "Low-Medium";
}

function parseRamCapacity(modules) {
  if (!modules) return 0;
  const str = String(modules);
  // Handle "2x16" format (AxB = A * B GB)
  if (str.includes("x")) {
    const parts = str.split("x").map(s => parseInt(s.trim()) || 0);
    return parts[0] * parts[1];
  }
  // Handle plain number format (total GB)
  return parseInt(str) || 0;
}

function getGamingGrade(score, selections) {
  const cpu = selections?.cpu;
  const ram = selections?.ram;
  const gpu = selections?.gpu;

  const cpuName = (cpu?.name || "").toLowerCase();
  const arch = (cpu?.microarchitecture || "").toLowerCase();
  const isZen3OrHigher = arch.includes("zen 3") || arch.includes("zen 4") || arch.includes("zen 5");
  const hasX3D = cpuName.includes("x3d") || cpuName.includes("3d v-cache");
  
  const ramName = (ram?.name || "").toLowerCase();
  const ramModules = (ram?.modules || "");
  const ramCapacity = parseRamCapacity(ramModules);
  
  const vram = toNumber(gpu?.memory);
  const gpuName = (gpu?.name || "").toLowerCase();
  const isHighEndGpu = gpuName.includes("rtx 40") || gpuName.includes("rtx 50") || gpuName.includes("rx 9");

  // X3D CPU bonus for gaming
  if (hasX3D && ramCapacity >= 32 && vram >= 8) {
    if (score >= 80) return "Elite Gaming";
    if (score >= 60) return "Extreme Gaming";
    if (score >= 40) return "High-End Gaming";
    return "Mid-Range Gaming";
  }
  
  // High-end builds with Zen 3+ and capable GPU
  if (isZen3OrHigher && ramCapacity >= 16 && vram >= 8) {
    if (score >= 90) return "Extreme Gaming";
    if (score >= 75) return "High-End Gaming";
    if (score >= 55) return "Mid-Range Gaming";
    return "Entry Gaming";
  }
  
  // Standard grading
  if (score >= 90) {
    return "Extreme Gaming";
  }
  if (score >= 75) {
    return "High-End Gaming";
  }
  if (score >= 55) {
    return "Mid-Range Gaming";
  }
  if (score >= 35) {
    return "Entry Gaming";
  }
  return "Basic Gaming";
}

function getPerformanceSummary(selections, score, optimalResolution, qualityPreset) {
  const cpu = selections?.cpu;
  const gpu = selections?.gpu;
  const ram = selections?.ram;
  
  const cpuName = (cpu?.name || "").toLowerCase();
  const hasX3D = cpuName.includes("x3d") || cpuName.includes("3d v-cache");
  const gpuName = (gpu?.name || "").toLowerCase();
  const vram = toNumber(gpu?.memory);
  
  const summaries = [];
  
  if (optimalResolution === "1440p") {
    summaries.push("1440p optimal - perfect balance of detail and performance");
  } else {
    summaries.push("1080p gaming - smooth frame rates at Full HD");
  }
  
  if (hasX3D) {
    summaries.push("X3D processor delivers lowest latency gaming");
  }
  
  if (vram >= 12) {
    summaries.push("Large VRAM buffer handles high-res textures with ease");
  }
  
  if (qualityPreset === "Ultra") {
    summaries.push("Ultra settings capable on modern titles");
  } else if (qualityPreset === "High") {
    summaries.push("High-ultra settings on most games");
  } else if (qualityPreset === "Medium-High") {
    summaries.push("High-medium settings on latest games");
  }
  
  if (gpuName.includes("rtx")) {
    summaries.push("NVIDIA DLSS & Ray Tracing available");
  }
  if (gpuName.includes("rx 7") || gpuName.includes("rx 9")) {
    summaries.push("AMD FSR available for enhanced performance");
  }
  
  return summaries;
}

function getResolutionCapabilities(gpuScore) {
  const caps = ["1080p"];
  if (gpuScore >= 65) caps.push("1440p");
  if (gpuScore >= 80) caps.push("4K");
  return caps;
}

function getGamePerformance(score, resolution, gpu) {
  const games = [
    { name: "Cyberpunk 2077", baseFps: 85 },
    { name: "Call of Duty: Warzone", baseFps: 120 },
    { name: "Fortnite", baseFps: 150 },
    { name: "Apex Legends", baseFps: 160 },
    { name: "Forza Horizon 5", baseFps: 100 },
    { name: "Black Myth: Wukong", baseFps: 55 },
    { name: "Elden Ring", baseFps: 60 },
    { name: "Grand Theft Auto VI", baseFps: 65 },
    { name: "Marvel Rivals", baseFps: 100 },
    { name: "Call of Duty: Black Ops 6", baseFps: 110 }
  ];

  const resMultiplier = resolution === "4K" || resolution === "4K/2K" ? 0.42 : resolution === "1440p" ? 0.65 : 1.0;
  const scoreFactor = (score / 50);
  const gpuName = (gpu?.name || "").toLowerCase();
  const hasScaling = gpuName.includes("rtx") || gpuName.includes("rx 6") || gpuName.includes("rx 7") || gpuName.includes("arc");
  const hasFrameGen = gpuName.includes("rtx 40") || gpuName.includes("rtx 50") || gpuName.includes("rx 7");
  const hasHighVram = (gpu?.memory || 0) >= 12;

  return games.map(game => {
    let fps = Math.round(game.baseFps * resMultiplier * scoreFactor);
    if (hasScaling) fps = Math.round(fps * 1.25);
    if (hasFrameGen) fps = Math.round(fps * 1.3);
    if (hasHighVram) fps = Math.round(fps * 1.05);
    return {
      name: game.name,
      rawFps: Math.round(game.baseFps * resMultiplier * scoreFactor),
      boostedFps: fps,
      hasScaling,
      hasFrameGen
    };
  });
}
