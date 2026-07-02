import { toNumber } from "./common.js";

export function calculateBottleneck(cpu, gpu) {
  if (!cpu || !gpu) return null;

  const cpuScore = scoreCpuForBottleneck(cpu);
  const gpuScore = scoreGpuForBottleneck(gpu);

  if (cpuScore <= 0 || gpuScore <= 0) return null;

  const total = cpuScore + gpuScore;
  const cpuPercent = Math.round((cpuScore / total) * 100);
  const gpuPercent = Math.round((gpuScore / total) * 100);

  const bottleneck = Math.abs(cpuPercent - gpuPercent);
  const bottleneckComponent = cpuPercent < gpuPercent ? "cpu" : "gpu";

  let severity = "Balanced";
  let recommendation = "Your CPU and GPU are well matched.";

  if (bottleneck >= 30) {
    severity = "Severe";
    recommendation = bottleneckComponent === "cpu"
      ? "Your CPU is significantly weaker than your GPU. Consider upgrading to a faster processor to unlock full GPU performance."
      : "Your GPU is significantly weaker than your CPU. Consider upgrading your graphics card for better gaming performance.";
  } else if (bottleneck >= 15) {
    severity = "Moderate";
    recommendation = bottleneckComponent === "cpu"
      ? "Your CPU is somewhat weaker than your GPU. You may experience lower FPS in CPU-intensive games."
      : "Your GPU is somewhat weaker than your CPU. Consider a GPU upgrade for better gaming performance.";
  } else if (bottleneck >= 5) {
    severity = "Mild";
    recommendation = bottleneckComponent === "cpu"
      ? "Slight CPU bottleneck in CPU-heavy scenarios. Overall performance is good."
      : "Slight GPU bottleneck in GPU-heavy scenarios. Overall performance is good.";
  }

  return {
    bottleneckPercent: bottleneck,
    bottleneckComponent,
    severity,
    recommendation,
    cpuScore,
    gpuScore,
    cpuPercent,
    gpuPercent,
  };
}

function scoreCpuForBottleneck(cpu) {
  const cores = toNumber(cpu.core_count);
  const threads = toNumber(cpu.threads) || cores;
  const baseClock = toNumber(cpu.core_clock);
  const boostClock = toNumber(cpu.boost_clock);
  const tdp = toNumber(cpu.tdp);

  const name = String(cpu.name ?? "").toLowerCase();
  const isX3D = name.includes("x3d") || name.includes("3d v-cache");
  const isModern = name.includes("ryzen 7") || name.includes("ryzen 9") || name.includes("core i7") || name.includes("core i9") || name.includes("core ultra");

  let score = 0;
  score += Math.min(cores * 15, 120);
  score += Math.min(threads * 5, 60);
  score += Math.min((boostClock || baseClock) * 30, 90);
  if (isX3D) score += 40;
  if (isModern) score += 20;
  if (tdp >= 120) score += 15;

  return score;
}

function scoreGpuForBottleneck(gpu) {
  const memory = toNumber(gpu.memory);
  const boostClock = toNumber(gpu.boost_clock);
  const tdp = toNumber(gpu.tdp);

  const name = String(gpu.name ?? "").toLowerCase();
  const isHighEnd = name.includes("rtx 40") || name.includes("rtx 50") || name.includes("rx 7") || name.includes("rx 9");
  const isMidRange = name.includes("rtx 30") || name.includes("rtx 20") || name.includes("rx 6") || name.includes("arc");

  let score = 0;
  score += Math.min(memory * 10, 100);
  score += Math.min((boostClock / 10) || 0, 80);
  if (isHighEnd) score += 60;
  if (isMidRange) score += 25;
  if (tdp >= 200) score += 20;
  if (memory >= 12) score += 20;

  return score;
}
