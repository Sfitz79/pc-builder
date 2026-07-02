import { describe, it, expect } from "vitest";
import { getPerformanceScore, getGamingPerformanceSummary } from "../performance";

describe("getPerformanceScore", () => {
  it("returns 0 for empty selections", () => {
    expect(getPerformanceScore({})).toBe(0);
  });

  it("returns a score between 0 and 100 for valid components", () => {
    const score = getPerformanceScore({
      cpu: { core_count: "8", core_clock: "3.5", boost_clock: "5.0" },
      gpu: { memory: "16", boost_clock: "2500" },
      ram: { speed: "6000", modules: "32", cas_latency: "30" },
      storage: { capacity: "2000", type: "NVMe" },
    });
    expect(score).toBeGreaterThanOrEqual(0);
    expect(score).toBeLessThanOrEqual(100);
  });
});

describe("getGamingPerformanceSummary", () => {
  it("returns full summary object with correct shape", () => {
    const summary = getGamingPerformanceSummary({
      cpu: { core_count: "8", core_clock: "3.5", boost_clock: "5.0", name: "AMD Ryzen 7 7800X3D", microarchitecture: "Zen 4" },
      gpu: { memory: "16", boost_clock: "2500", name: "NVIDIA RTX 4080", chipset: "RTX 4080", tdp: "320" },
      ram: { speed: "6000", modules: "32", cas_latency: "30" },
      storage: { capacity: "2000", type: "NVMe" },
    });

    expect(summary).toHaveProperty("score");
    expect(summary).toHaveProperty("fps1080p");
    expect(summary).toHaveProperty("qualityPreset");
    expect(summary).toHaveProperty("gamingGrade");
    expect(summary).toHaveProperty("resolutionCapabilities");
    expect(summary).toHaveProperty("optimalResolution");
    expect(summary).toHaveProperty("performanceSummary");
    expect(summary).toHaveProperty("gamePerformance");
    expect(summary).toHaveProperty("allResPerformance");
  });

  it("generates quality preset based on score", () => {
    const highEnd = getGamingPerformanceSummary({
      cpu: { core_count: "16", core_clock: "4.0", boost_clock: "5.5", name: "AMD Ryzen 9 9950X3D", microarchitecture: "Zen 5" },
      gpu: { memory: "24", boost_clock: "2600", name: "NVIDIA RTX 5090", chipset: "RTX 5090", tdp: "450" },
      ram: { speed: "8000", modules: "64", cas_latency: "28" },
      storage: { capacity: "4000", type: "NVMe" },
    });
    expect(highEnd.qualityPreset).toBe("Ultra");

    const lowEnd = getGamingPerformanceSummary({});
    expect(lowEnd.qualityPreset).toBeDefined();
  });
});
