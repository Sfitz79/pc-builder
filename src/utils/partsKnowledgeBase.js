export const GPU_HIERARCHY = {
  enthusiast: {
    label: "4K Ultra / Enthusiast",
    cards: [
      { name: "RTX 5090", tier: "Flagship", vram: "32GB GDDR7", msrp: "$1999+", note: "Best overall. 169 FPS avg at 4K. Overkill for most." },
      { name: "RTX 5080", tier: "High-End", vram: "16GB GDDR7", msrp: "$999+", note: "Excellent 4K. 115 FPS avg at 4K. Best high-end value." },
      { name: "RTX 5070 Ti", tier: "High-End", vram: "16GB GDDR7", msrp: "$749+", note: "Great 4K entry / 1440p king. 98 FPS at 4K." }
    ]
  },
  highEnd: {
    label: "1440p High / Entry 4K",
    cards: [
      { name: "RX 9070 XT", tier: "Upper Mid", vram: "16GB GDDR6", msrp: "$600", note: "Best AMD value. 93 FPS at 4K, strong 1440p perf." },
      { name: "RX 9070", tier: "Mid-Range", vram: "16GB GDDR6", msrp: "$550", note: "Solid 1440p card. Slightly behind 9070 XT." },
      { name: "RTX 5070", tier: "Mid-Range", vram: "12GB GDDR7", msrp: "$550", note: "Best value NVIDIA. Great price-to-perf ratio." }
    ]
  },
  midRange: {
    label: "1080p High / 1440p Medium",
    cards: [
      { name: "RX 9060 XT", tier: "Mid-Range", vram: "12GB GDDR6", msrp: "$350", note: "Strong 1080p/1440p card." },
      { name: "RTX 5060 Ti", tier: "Mid-Range", vram: "8-16GB GDDR7", msrp: "$300+", note: "Good 1080p-1440p option." },
      { name: "RTX 5060", tier: "Entry", vram: "8GB GDDR7", msrp: "$250+", note: "Solid 1080p card." },
      { name: "Intel Arc B580", tier: "Budget", vram: "12GB GDDR6", msrp: "$250", note: "Best budget option. Good value." }
    ]
  },
  budget: {
    label: "1080p Entry / Budget",
    cards: [
      { name: "RTX 4060", tier: "Entry", vram: "8GB GDDR6", msrp: "$300", note: "Older gen but still capable at 1080p." },
      { name: "RX 7600", tier: "Entry", vram: "8GB GDDR6", msrp: "$270", note: "Solid 1080p gaming." }
    ]
  }
};

export const CPU_HIERARCHY = {
  bestGaming: {
    label: "Best Gaming CPU",
    cpus: [
      { name: "Ryzen 7 9800X3D", cores: 8, threads: 16, socket: "AM5", tdp: "120W", price: "$460", note: "Best gaming CPU 2026. 30-38% faster than Intel Arrow Lake in games. 96MB L3 cache." },
      { name: "Ryzen 9 9950X3D", cores: 16, threads: 32, socket: "AM5", tdp: "170W", price: "$675", note: "Best gaming + productivity hybrid. 3D V-Cache on one CCD." },
      { name: "Ryzen 9 9900X3D", cores: 12, threads: 24, socket: "AM5", tdp: "120W", price: "$550", note: "Excellent gaming + multi-threaded perf." }
    ]
  },
  highEnd: {
    label: "1440p/4K Gaming & Productivity",
    cpus: [
      { name: "Ryzen 7 9700X", cores: 8, threads: 16, socket: "AM5", tdp: "65W", price: "$309", note: "Best value gaming CPU. 90-95% of 9800X3D perf at 1440p for much less." },
      { name: "Ryzen 5 9600X", cores: 6, threads: 12, socket: "AM5", tdp: "65W", price: "$165", note: "Best budget gaming CPU. Unmatched value." },
      { name: "Intel Core Ultra 7 265K", cores: "8P+12E", threads: 20, socket: "LGA1851", tdp: "125W", price: "$350", note: "Good for productivity, decent gaming." }
    ]
  },
  midRange: {
    label: "Mid-Range / Budget",
    cpus: [
      { name: "Ryzen 5 7600", cores: 6, threads: 12, socket: "AM5", tdp: "65W", price: "$185", note: "Great entry-level AM5. Solid gaming perf." },
      { name: "Ryzen 7 7800X3D", cores: 8, threads: 16, socket: "AM5", tdp: "120W", price: "$350", note: "Previous-gen X3D. Still excellent if found at good price." },
      { name: "Intel Core Ultra 5 245K", cores: "6P+8E", threads: 14, socket: "LGA1851", tdp: "125W", price: "$280", note: "Entry Intel Arrow Lake." }
    ]
  }
};

export const GAME_REQUIREMENTS = {
  "cyberpunk-2077": {
    name: "Cyberpunk 2077 + Phantom Liberty",
    min: { cpu: "i7-6700 / Ryzen 5 1600", gpu: "GTX 1060 6GB / RX 580 8GB", ram: "12GB", storage: "70GB SSD", os: "Win 10", fps: "30@1080p Low" },
    rec: { cpu: "i7-12700 / Ryzen 7 7800X3D", gpu: "RTX 2060S / RX 5700 XT", ram: "16GB", storage: "70GB SSD", os: "Win 10", fps: "60@1080p High" },
    ultra: { cpu: "i9-12900 / Ryzen 9 7900X", gpu: "RTX 3080 / RX 7900 XTX", ram: "20GB", storage: "70GB NVMe", os: "Win 10", fps: "60@4K Ultra" },
    rtOverdrive: { cpu: "i9-12900 / Ryzen 9 7900X", gpu: "RTX 4080", ram: "24GB", storage: "70GB NVMe", os: "Win 10", fps: "60@4K Path Tracing" }
  },
  "battlefield-6": {
    name: "Battlefield 6",
    min: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "RTX 2060 / RX 5600 XT", ram: "16GB", storage: "55GB HDD", os: "Win 10", fps: "60@1080p Low" },
    rec: { cpu: "i7-10700 / Ryzen 7 3700X", gpu: "RTX 3060 Ti / RX 6700 XT", ram: "16GB", storage: "80GB SSD", os: "Win 11", fps: "60@1440p High" }
  },
  "black-myth-wukong": {
    name: "Black Myth: Wukong",
    min: { cpu: "i5-8400 / Ryzen 5 1600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "130GB SSD", os: "Win 10", fps: "30@1080p Low" },
    rec: { cpu: "i7-9700 / Ryzen 5 5500", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "130GB SSD", os: "Win 10", fps: "60@1080p High" }
  },
  "cod-black-ops-6": {
    name: "Call of Duty: Black Ops 6",
    min: { cpu: "i5-6600 / Ryzen 5 1400", gpu: "GTX 960 / RX 470 / Arc A580", ram: "8GB", storage: "102GB SSD", os: "Win 10", fps: "60@1080p Low" },
    rec: { cpu: "i7-6700K / Ryzen 5 1600X", gpu: "RTX 3060 / GTX 1080 Ti / RX 6600 XT", ram: "12GB", storage: "102GB SSD", os: "Win 10/11", fps: "60@1080p High" },
    comp4k: { cpu: "i7-8700K / Ryzen 7 2700X", gpu: "RTX 3080 / RTX 4070 / RX 6800 XT", ram: "16GB", storage: "102GB SSD", os: "Win 10/11", fps: "High FPS@4K Competitive" }
  },
  "starfield": {
    name: "Starfield",
    min: { cpu: "i5-8400", gpu: "RTX 1070 Ti", ram: "16GB", storage: "SSD Required", os: "Win 10", fps: "30@1080p Low" }
  },
  "alan-wake-2": {
    name: "Alan Wake II",
    min: { cpu: "i5-7600K", gpu: "RTX 2060S", ram: "16GB", storage: "SSD Required", os: "Win 10", fps: "30@1080p Low" }
  },
  "assassins-creed-shadows": {
    name: "Assassin's Creed Shadows",
    min: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "SSD Required", os: "Win 10" }
  }
};

export const OS_REQUIREMENTS = {
  windows11: {
    name: "Windows 11 24H2",
    minRequirements: {
      cpu: "1 GHz+ dual-core 64-bit compatible processor",
      ram: "4GB",
      storage: "64GB",
      firmware: "UEFI with Secure Boot",
      tpm: "TPM 2.0 required",
      gpu: "DirectX 12 compatible with WDDM 2.0 driver",
      display: "720p, >9\" diagonal, 8-bit colour"
    },
    notes: [
      "Intel 8th Gen (Coffee Lake) and newer supported",
      "AMD Ryzen 2000 series and newer supported",
      "TPM 2.0 must be enabled in BIOS",
      "Internet + Microsoft account required for initial setup (Home edition)",
      "Windows 11 Pro can use local account"
    ]
  },
  windows10: {
    name: "Windows 10 22H2",
    minRequirements: {
      cpu: "1 GHz+ processor",
      ram: "1GB (32-bit) / 2GB (64-bit)",
      storage: "16GB (32-bit) / 32GB (64-bit)",
      gpu: "DirectX 9 or later with WDDM 1.0"
    },
    notes: [
      "Support ended October 2025",
      "No longer receiving security updates",
      "Some newer games may not support Win 10 anymore"
    ]
  }
};

export const BUILD_RECOMMENDATIONS_BY_BUDGET = {
  budget_500_700: {
    range: "£500-£700",
    label: "Entry Level - 1080p Gaming",
    cpu: "Ryzen 5 5600 / 7600",
    gpu: "Intel Arc B580 / RTX 4060",
    ram: "16GB DDR4/DDR5",
    storage: "1TB NVMe",
    note: "Great for esports (Valorant, CS2, Fortnite) and medium-settings AAA at 1080p"
  },
  budget_800_1200: {
    range: "£800-£1,200",
    label: "Sweet Spot - 1440p Gaming",
    cpu: "Ryzen 7 9700X / Ryzen 5 7600X",
    gpu: "RTX 5070 / RX 9070",
    ram: "32GB DDR5-6000",
    storage: "2TB NVMe Gen4",
    note: "Best value tier. RTX 5070 is a generational step forward. Handles everything at 1440p comfortably."
  },
  budget_1500_2000: {
    range: "£1,500-£2,000",
    label: "High-End - 1440p/Entry 4K",
    cpu: "Ryzen 7 9800X3D / Ryzen 9 9900X",
    gpu: "RTX 5070 Ti (16GB) / RX 9070 XT",
    ram: "32GB DDR5-6000 CL30",
    storage: "2TB NVMe Gen5",
    note: "Excellent 1440p, strong 4K in most titles. RTX 5070 Ti has 16GB VRAM for future-proofing."
  },
  budget_2000_3000: {
    range: "£2,000-£3,000",
    label: "Flagship - 4K Gaming",
    cpu: "Ryzen 7 9800X3D / Ryzen 9 9950X3D",
    gpu: "RTX 5080 (16GB GDDR7)",
    ram: "32-64GB DDR5-6000 CL30",
    storage: "2-4TB NVMe Gen5",
    note: "Native 4K at ultra settings. The RTX 5080 handles everything. Competitive for 6-8 years."
  },
  budget_3000_plus: {
    range: "£3,000+",
    label: "No-Compromise - 4K Ultra/RT",
    cpu: "Ryzen 9 9950X3D",
    gpu: "RTX 5090 (32GB GDDR7)",
    ram: "64GB DDR5-6000+ CL30",
    storage: "4TB+ NVMe Gen5",
    note: "Absolute best. RTX 5090 does 169 FPS avg at 4K. Overkill for most, best for high-refresh 4K."
  }
};

export const RESOLUTION_GUIDE = {
  "1080p": {
    label: "1080p (Full HD)",
    gpu: "RTX 4060 / Arc B580 / RX 7600 — mid-range",
    cpu: "Ryzen 5 5600/7600 / Core i5",
    note: "Great for competitive gaming at high FPS. Budget-friendly."
  },
  "1440p": {
    label: "1440p (QHD)",
    gpu: "RTX 5070 / RX 9070 / RTX 5070 Ti — mid-high range",
    cpu: "Ryzen 7 9700X / 9800X3D",
    note: "The sweet spot in 2026. Great balance of visual quality and performance."
  },
  "4k": {
    label: "4K (UHD)",
    gpu: "RTX 5080 / RTX 5090 — high-end to flagship",
    cpu: "Ryzen 7 9800X3D / Ryzen 9 9950X3D",
    note: "Demands top-tier GPU. RTX 5080 minimum for good 4K, RTX 5090 for high-refresh 4K."
  }
};

export const GAMING_PC_4K_BUILDS = [
  {
    title: "1. Entry 4K Gaming PC",
    price: "£1,450 - £1,600",
    gpu: "NVIDIA GeForce RTX 5070 12GB",
    cpu: "AMD Ryzen 5 9600X (6 Cores)",
    ram: "16GB DDR5 5600MHz",
    storage: "1TB PCIe 4.0 NVMe SSD",
    psu: "750W 80+ Gold"
  },
  {
    title: "2. Mid-Range 4K Gaming PC",
    price: "£2,100 - £2,350",
    gpu: "NVIDIA GeForce RTX 5070 Ti 16GB",
    cpu: "AMD Ryzen 7 9700X (8 Cores)",
    ram: "32GB DDR5 6000MHz",
    storage: "2TB PCIe 4.0 NVMe SSD",
    psu: "850W 80+ Gold"
  },
  {
    title: "3. Competitive Esports 4K PC",
    price: "£3,000 - £3,300",
    gpu: "NVIDIA GeForce RTX 5080 16GB",
    cpu: "AMD Ryzen 7 9800X3D (8 Cores)",
    ram: "32GB DDR5 6000MHz (Low Latency)",
    storage: "2TB PCIe 5.0 NVMe SSD",
    psu: "850W - 1000W Platinum PSU"
  },
  {
    title: "4. Ultimate 4K Gaming & AI Workstation",
    price: "£4,500 - £5,200",
    gpu: "NVIDIA GeForce RTX 5090 32GB",
    cpu: "Intel Core Ultra 9 285K or AMD Ryzen 9 9900X (12+ Cores)",
    ram: "64GB DDR5 6400MHz",
    storage: "4TB PCIe 5.0 NVMe SSD",
    psu: "1200W+ 80+ Titanium"
  }
];

export const GAMING_STREAMING_4K_BUILDS = [
  {
    title: "1. Entry Tier (Budget 4K & Streaming)",
    description: "Ideal for hitting 4K resolutions using AI-driven scaling (DLSS/FSR) and streaming at 1440p.",
    cpu: "AMD Ryzen 5 7600X",
    gpu: "NVIDIA GeForce RTX 5060 Ti (16GB GDDR7)",
    ram: "32GB (2 x 16GB) DDR5-6000",
    storage: "1TB NVMe PCIe 4.0 SSD",
    cooling: "Thermalright Assassin Air Cooler",
    psu: "Corsair 650W 80+ Gold",
    price: "£1,050 - £1,200"
  },
  {
    title: "2. Mid Tier (High-Refresh & Streaming)",
    description: "Maintains solid 4K framerates in AAA titles with heavier ray-tracing, and perfectly encodes 1440p streaming output.",
    cpu: "AMD Ryzen 7 7700X",
    gpu: "AMD Radeon RX 9070 XT (16GB GDDR6)",
    ram: "32GB (2 x 16GB) DDR5-6000",
    storage: "2TB NVMe PCIe 4.0 SSD",
    cooling: "DeepCool AK620 Air Cooler",
    psu: "Corsair 750W 80+ Gold",
    price: "£1,450 - £1,650"
  },
  {
    title: "3. Competitive Tier (Esports & Ultra-Settings 4K)",
    description: "Built to maximize FPS for competitive gaming at 4K, while managing background 1440p broadcasting without dropping frames.",
    cpu: "AMD Ryzen 7 9800X3D",
    gpu: "NVIDIA GeForce RTX 5080 (16GB GDDR6X)",
    ram: "32GB (2 x 16GB) DDR5-6400",
    storage: "2TB NVMe PCIe 4.0 SSD",
    cooling: "Corsair iCUE H115i RGB Liquid Cooler",
    psu: "Corsair 850W 80+ Gold",
    price: "£2,300 - £2,600"
  },
  {
    title: "4. Ultimate Tier (Max Settings 4K, VR & Multitasking)",
    description: "The uncompromised build for running ultra-settings 4K gaming, 1440p streaming, and intensive local AI rendering.",
    cpu: "AMD Ryzen 9 9950X",
    gpu: "NVIDIA GeForce RTX 5090 (24GB GDDR6X)",
    ram: "64GB (2 x 32GB) DDR5-6400",
    storage: "4TB NVMe PCIe 5.0 SSD",
    cooling: "ASUS ROG Ryujin III 360 Liquid Cooler",
    psu: "Corsair 1200W 80+ Platinum",
    price: "£4,200 - £4,600"
  }
];

export const USE_CASE_GUIDE = {
  gaming: {
    label: "Gaming",
    priorities: ["GPU > CPU for most games", "X3D CPUs best for gaming", "32GB RAM recommended"],
    note: "GPU is most important. Ryzen X3D CPUs give 15-25% more FPS in CPU-limited scenarios."
  },
  streaming: {
    label: "Streaming / Content Creation",
    priorities: ["More CPU cores (8+ recommended)", "32GB RAM minimum", "NVIDIA GPU for NVENC encoder"],
    note: "Ryzen 7 or Intel Core i7+ with 32GB RAM. NVIDIA GPUs have best streaming encoder (NVENC)."
  },
  productivity: {
    label: "Productivity / Work",
    priorities: ["CPU multi-core performance", "32-64GB RAM", "Fast NVMe storage"],
    note: "Prioritize CPU cores and RAM. Intel Core Ultra and Ryzen 9 are strong options."
  },
  "3d-rendering": {
    label: "3D Rendering / Video Editing",
    priorities: ["High core count CPU", "Large VRAM GPU", "64GB+ RAM", "Gen5 NVMe storage"],
    note: "Ryzen 9 9950X3D or Intel Core Ultra 9. RTX 4090/5090 for GPU rendering. 64GB RAM recommended."
  },
  general: {
    label: "General Use / Office",
    priorities: ["Balanced mid-range parts", "16-32GB RAM", "Fast SSD"],
    note: "Mid-range CPU with integrated graphics or entry GPU is sufficient."
  }
};
