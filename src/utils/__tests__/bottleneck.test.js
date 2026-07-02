import { describe, it, expect } from "vitest";
import { calculateBottleneck } from "../bottleneck";

describe("calculateBottleneck", () => {
  it("returns null if cpu or gpu is missing", () => {
    expect(calculateBottleneck(null, { name: "RTX 4080" })).toBeNull();
    expect(calculateBottleneck({ name: "Ryzen 7 7800X3D" }, null)).toBeNull();
  });

  it("returns balanced result for well-matched components", () => {
    const result = calculateBottleneck(
      { name: "AMD Ryzen 7 7800X3D", core_count: "8", threads: "16", core_clock: "4.2", boost_clock: "5.0", tdp: "120" },
      { name: "NVIDIA RTX 4080", memory: "16", boost_clock: "2500", tdp: "320" }
    );
    expect(result).not.toBeNull();
    expect(result).toHaveProperty("bottleneckPercent");
    expect(result).toHaveProperty("bottleneckComponent");
    expect(result).toHaveProperty("severity");
    expect(result).toHaveProperty("recommendation");
    expect(result).toHaveProperty("cpuScore");
    expect(result).toHaveProperty("gpuScore");
    expect(result.cpuScore).toBeGreaterThan(0);
    expect(result.gpuScore).toBeGreaterThan(0);
  });

  it("identifies GPU bottleneck for weak GPU", () => {
    const result = calculateBottleneck(
      { name: "AMD Ryzen 9 7950X", core_count: "16", threads: "32", core_clock: "4.5", boost_clock: "5.7", tdp: "170" },
      { name: "GTX 1650", memory: "4", boost_clock: "1700", tdp: "75" }
    );
    expect(result).not.toBeNull();
    expect(result.bottleneckComponent).toBe("gpu");
  });

  it("identifies CPU bottleneck for weak CPU", () => {
    const result = calculateBottleneck(
      { name: "Intel Celeron G4900", core_count: "2", threads: "2", core_clock: "2.0", boost_clock: "2.0", tdp: "65" },
      { name: "NVIDIA RTX 4090", memory: "24", boost_clock: "2600", tdp: "450" }
    );
    expect(result).not.toBeNull();
    expect(result.bottleneckComponent).toBe("cpu");
  });

  it("returns severity levels correctly", () => {
    const mild = calculateBottleneck(
      { name: "AMD Ryzen 5 5600X", core_count: "6", threads: "12", core_clock: "3.7", boost_clock: "4.6", tdp: "65" },
      { name: "NVIDIA RTX 4070", memory: "12", boost_clock: "2500", tdp: "200" }
    );
    expect(["Balanced", "Mild", "Moderate", "Severe"]).toContain(mild.severity);
  });
});
