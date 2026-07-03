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

export const GAME_CATEGORIES = {
  fps: { label: "FPS / Battle Royale", icon: "🔫", color: "#e74c3c" },
  rpg: { label: "RPG / Open World", icon: "⚔️", color: "#9b59b6" },
  action: { label: "Action / Adventure", icon: "🎬", color: "#e67e22" },
  survival: { label: "Survival / Crafting", icon: "⛏️", color: "#27ae60" },
  sim: { label: "Simulation / Strategy", icon: "🏗️", color: "#3498db" },
  fighting: { label: "MOBA / Fighting", icon: "🥊", color: "#f39c12" },
  racing: { label: "Racing / Sports", icon: "🏎️", color: "#1abc9c" },
  horror: { label: "Horror / Co-op", icon: "👻", color: "#8e44ad" },
  mmo: { label: "MMO / Online", icon: "🌐", color: "#2c3e50" }
};

export const GAME_REQUIREMENTS = {
  "cyberpunk-2077": {
    name: "Cyberpunk 2077 + Phantom Liberty", icon: "🌃", category: "rpg",
    min: { cpu: "i7-6700 / Ryzen 5 1600", gpu: "GTX 1060 6GB / RX 580 8GB", ram: "12GB", storage: "70GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-12700 / Ryzen 7 7800X3D", gpu: "RTX 2060S / RX 5700 XT", ram: "16GB", storage: "70GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900 / Ryzen 9 7900X", gpu: "RTX 3080 / RX 7900 XTX", ram: "20GB", storage: "70GB NVMe", fps: "60@4K Ultra" }
  },
  "battlefield-6": {
    name: "Battlefield 6", icon: "🔫", category: "fps",
    min: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "RTX 2060 / RX 5600 XT", ram: "16GB", storage: "55GB HDD", fps: "60@1080p Low" },
    rec: { cpu: "i7-10700 / Ryzen 7 3700X", gpu: "RTX 3060 Ti / RX 6700 XT", ram: "16GB", storage: "80GB SSD", fps: "60@1440p High" },
    above: { cpu: "i9-12900K / Ryzen 7 7800X3D", gpu: "RTX 4070 Ti / RX 7900 XT", ram: "32GB", storage: "1TB NVMe", fps: "144@1440p Ultra" }
  },
  "black-myth-wukong": {
    name: "Black Myth: Wukong", icon: "🐵", category: "action",
    min: { cpu: "i5-8400 / Ryzen 5 1600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "130GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-9700 / Ryzen 5 5500", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "130GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-13700 / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 7800 XT", ram: "32GB", storage: "2TB NVMe", fps: "60@4K High" }
  },
  "cod-black-ops-6": {
    name: "Call of Duty: Black Ops 6", icon: "🎯", category: "fps",
    min: { cpu: "i5-6600 / Ryzen 5 1400", gpu: "GTX 960 / RX 470", ram: "8GB", storage: "102GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i7-6700K / Ryzen 5 1600X", gpu: "RTX 3060 / RX 6600 XT", ram: "12GB", storage: "102GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 6800 XT", ram: "32GB", storage: "2TB NVMe", fps: "144@1440p Competitive" }
  },
  "fortnite": {
    name: "Fortnite", icon: "🏰", category: "fps",
    min: { cpu: "i3-3225 / Ryzen 3 1200", gpu: "Intel HD 4000 / Radeon Vega 8", ram: "8GB", storage: "30GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-7300U / Ryzen 5 1400", gpu: "GTX 960 / RX 460", ram: "16GB", storage: "30GB SSD", fps: "60@1080p Medium" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 3060 / RX 6600 XT", ram: "16GB", storage: "256GB SSD", fps: "144@1440p Epic" }
  },
  "grand-theft-auto-6": {
    name: "Grand Theft Auto VI", icon: "🚗", category: "action",
    min: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "150GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-12700K / Ryzen 7 5800X", gpu: "RTX 3070 / RX 6800", ram: "16GB", storage: "150GB NVMe", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4080 / RX 7900 XTX", ram: "32GB", storage: "2TB NVMe", fps: "60@4K Ultra" }
  },
  "marvel-rivals": {
    name: "Marvel Rivals", icon: "🦸", category: "fighting",
    min: { cpu: "i5-6600K / Ryzen 5 1600", gpu: "GTX 1060 / RX 580", ram: "12GB", storage: "70GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i7-10700K / Ryzen 7 3700X", gpu: "RTX 2070 Super / RX 5700 XT", ram: "16GB", storage: "70GB SSD", fps: "60@1440p High" },
    above: { cpu: "i9-12900K / Ryzen 7 7800X3D", gpu: "RTX 4070 Ti / RX 7800 XT", ram: "32GB", storage: "1TB NVMe", fps: "144@1440p High" }
  },
  "elden-ring": {
    name: "Elden Ring", icon: "⚔️", category: "rpg",
    min: { cpu: "i5-8400 / Ryzen 3 3300X", gpu: "GTX 1060 3GB / RX 580 4GB", ram: "12GB", storage: "60GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700K / Ryzen 5 3600X", gpu: "GTX 1070 / RX Vega 56", ram: "16GB", storage: "60GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-12700K / Ryzen 7 5800X3D", gpu: "RTX 3070 / RX 6800", ram: "32GB", storage: "1TB NVMe", fps: "60@4K High" }
  },
  "starfield": {
    name: "Starfield", icon: "🚀", category: "rpg",
    min: { cpu: "i5-8400 / Ryzen 5 1600", gpu: "GTX 1070 Ti / RX 5700", ram: "16GB", storage: "125GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-10700K / Ryzen 5 3600", gpu: "RTX 2070 / RX 6700 XT", ram: "16GB", storage: "125GB NVMe", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4080 / RX 7900 XTX", ram: "32GB", storage: "2TB NVMe", fps: "60@4K Ultra" }
  },
  "alan-wake-2": {
    name: "Alan Wake II", icon: "🔦", category: "horror",
    min: { cpu: "i5-7600K / Ryzen 5 2600", gpu: "RTX 2060 / RX 6600", ram: "16GB", storage: "90GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-10700K / Ryzen 7 3700X", gpu: "RTX 3060 / RX 6700 XT", ram: "16GB", storage: "90GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4070 Ti / RX 7900 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@4K High" }
  },
  "assassins-creed-shadows": {
    name: "Assassin's Creed Shadows", icon: "🗡️", category: "action",
    min: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "100GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-11700K / Ryzen 5 5600X", gpu: "RTX 3070 / RX 6800", ram: "32GB", storage: "100GB SSD", fps: "60@1440p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4080 / RX 7900 XTX", ram: "32GB", storage: "2TB NVMe", fps: "60@4K Ultra" }
  },
  "red-dead-redemption-2": {
    name: "Red Dead Redemption 2", icon: "🐴", category: "action",
    min: { cpu: "i5-2500K / Ryzen 3 1200", gpu: "GTX 770 / RX 470", ram: "8GB", storage: "150GB HDD", fps: "30@1080p Low" },
    rec: { cpu: "i7-4770K / Ryzen 5 1500X", gpu: "GTX 1060 6GB / RX 480 4GB", ram: "12GB", storage: "150GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-9900K / Ryzen 7 5800X", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@4K Ultra" }
  },
  "hogwarts-legacy": {
    name: "Hogwarts Legacy", icon: "🧙", category: "rpg",
    min: { cpu: "i5-6600 / Ryzen 5 1400", gpu: "GTX 960 / RX 470", ram: "16GB", storage: "85GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "85GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@4K Ultra" }
  },
  "god-of-war-ragnarok": {
    name: "God of War Ragnarok", icon: "🔨", category: "action",
    min: { cpu: "i5-6600K / Ryzen 5 2600", gpu: "GTX 1060 / RX 580", ram: "12GB", storage: "70GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-9700K / Ryzen 7 3700X", gpu: "RTX 2060 Super / RX 5700 XT", ram: "16GB", storage: "70GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 7800X3D", gpu: "RTX 3080 Ti / RX 7900 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@4K Ultra" }
  },
  "the-last-of-us": {
    name: "The Last of Us Part I", icon: "🌿", category: "action",
    min: { cpu: "i5-6600K / Ryzen 5 1400", gpu: "GTX 970 / RX 580", ram: "16GB", storage: "100GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "100GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@4K High" }
  },
  "valorant": {
    name: "Valorant", icon: "🔫", category: "fps",
    min: { cpu: "i3-370M / Ryzen 3 1200", gpu: "Intel HD 3000 / Radeon HD 5000", ram: "4GB", storage: "30GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 5 1600", gpu: "GTX 1050 Ti / RX 560", ram: "8GB", storage: "30GB SSD", fps: "144@1080p High" },
    above: { cpu: "i7-9700K / Ryzen 7 5800X", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "128GB NVMe", fps: "240+@1440p Competitive" }
  },
  "counter-strike-2": {
    name: "Counter-Strike 2", icon: "💣", category: "fps",
    min: { cpu: "i5-7500 / Ryzen 5 1600", gpu: "GTX 1050 Ti / RX 470", ram: "8GB", storage: "85GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i7-9700K / Ryzen 7 3700X", gpu: "RTX 2070 / RX 5700 XT", ram: "16GB", storage: "85GB SSD", fps: "144@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 7800 XT", ram: "32GB", storage: "512GB NVMe", fps: "300+@1440p Competitive" }
  },
  "apex-legends": {
    name: "Apex Legends", icon: "⚡", category: "fps",
    min: { cpu: "i5-3570K / Ryzen 5 1400", gpu: "GTX 960 / RX 470", ram: "8GB", storage: "65GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-9700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "65GB NVMe", fps: "144@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "512GB NVMe", fps: "144@1440p Competitive" }
  },
  "forza-horizon-5": {
    name: "Forza Horizon 5", icon: "🏎️", category: "racing",
    min: { cpu: "i5-4460 / Ryzen 3 1200", gpu: "GTX 970 / RX 470", ram: "8GB", storage: "110GB HDD", fps: "30@1080p Low" },
    rec: { cpu: "i7-10700K / Ryzen 5 3600", gpu: "RTX 2070 / RX 6700 XT", ram: "16GB", storage: "110GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 Ti / RX 7900 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@4K Extreme" }
  },
  "call-of-duty-warzone": {
    name: "Call of Duty: Warzone", icon: "🎯", category: "fps",
    min: { cpu: "i5-6600 / Ryzen 5 1400", gpu: "GTX 960 / RX 470", ram: "8GB", storage: "125GB HDD", fps: "60@1080p Low" },
    rec: { cpu: "i7-8700K / Ryzen 7 2700X", gpu: "RTX 3060 Ti / RX 5700 XT", ram: "16GB", storage: "125GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 6800 XT", ram: "32GB", storage: "1TB NVMe", fps: "144@1440p Competitive" }
  },
  "minecraft": {
    name: "Minecraft", icon: "⛏️", category: "survival",
    min: { cpu: "i3-3210 / Ryzen 3 1200", gpu: "Intel HD 4000 / Radeon HD 7000", ram: "4GB", storage: "1GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-4690 / Ryzen 5 1600", gpu: "GTX 1060 / RX 580", ram: "8GB", storage: "4GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-12700K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "512GB NVMe", fps: "60@4K Ultra Shaders" }
  },
  "diablo-4": {
    name: "Diablo IV", icon: "💀", category: "rpg",
    min: { cpu: "i5-7500 / Ryzen 3 1200", gpu: "GTX 1050 Ti / RX 470", ram: "8GB", storage: "45GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "45GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "512GB NVMe", fps: "60@4K Ultra" }
  },
  "baldurs-gate-3": {
    name: "Baldur's Gate 3", icon: "🐉", category: "rpg",
    min: { cpu: "i5-4690 / Ryzen 3 1200", gpu: "GTX 970 / RX 480", ram: "8GB", storage: "150GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 Super / RX 5700 XT", ram: "16GB", storage: "150GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@4K Ultra" }
  },
  "f1-24": {
    name: "F1 24", icon: "🏁", category: "racing",
    min: { cpu: "i5-8600K / Ryzen 5 2600X", gpu: "GTX 1060 / RX 580", ram: "8GB", storage: "100GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-10700K / Ryzen 7 3700X", gpu: "RTX 3060 Ti / RX 6700 XT", ram: "16GB", storage: "100GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4080 / RX 7900 XT", ram: "32GB", storage: "1TB NVMe", fps: "120@4K Ultra" }
  },
  "rainbow-six-siege": {
    name: "Rainbow Six Siege", icon: "🔒", category: "fps",
    min: { cpu: "i5-6600K / Ryzen 3 1200", gpu: "GTX 670 / Radeon HD 7970", ram: "8GB", storage: "61GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "61GB SSD", fps: "144@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "256GB NVMe", fps: "240+@1440p Competitive" }
  },
  "flight-simulator-2024": {
    name: "Microsoft Flight Simulator 2024", icon: "✈️", category: "sim",
    min: { cpu: "i7-6800K / Ryzen 5 2600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "50GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-10700K / Ryzen 7 3700X", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "50GB SSD", fps: "30@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 9 7950X3D", gpu: "RTX 4090 / RX 7900 XTX", ram: "64GB", storage: "2TB NVMe", fps: "60@4K Ultra" }
  },
  "star-wars-outlaws": {
    name: "Star Wars Outlaws", icon: "🌌", category: "action",
    min: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "65GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-12700K / Ryzen 7 5800X", gpu: "RTX 3070 / RX 6800", ram: "16GB", storage: "65GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4080 / RX 7900 XTX", ram: "32GB", storage: "1TB NVMe", fps: "60@4K Ultra" }
  },
  "dragon-age-veilguard": {
    name: "Dragon Age: The Veilguard", icon: "🐉", category: "rpg",
    min: { cpu: "i5-8400 / Ryzen 3 3300X", gpu: "GTX 970 / RX 480", ram: "16GB", storage: "100GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-9700K / Ryzen 5 3600X", gpu: "RTX 2070 / RX 5700 XT", ram: "16GB", storage: "100GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4070 Ti / RX 7900 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@4K Ultra" }
  },
  "helldivers-2": {
    name: "Helldivers 2", icon: "🪖", category: "horror",
    min: { cpu: "i7-4790K / Ryzen 5 1500X", gpu: "GTX 1050 Ti / RX 470", ram: "8GB", storage: "100GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-9700K / Ryzen 7 3700X", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "100GB SSD", fps: "60@1080p Medium" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "512GB NVMe", fps: "60@1440p Ultra" }
  },
  "world-of-warcraft": {
    name: "World of Warcraft: The War Within", icon: "🗺️", category: "mmo",
    min: { cpu: "i5-8400 / Ryzen 3 3300X", gpu: "GTX 970 / RX 480", ram: "8GB", storage: "128GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-10700K / Ryzen 5 3600X", gpu: "RTX 3060 / RX 6600 XT", ram: "16GB", storage: "128GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 7800 XT", ram: "32GB", storage: "512GB NVMe", fps: "144@1440p Ultra" }
  },
  "ark-survival-ascended": {
    name: "ARK: Survival Ascended", icon: "🦖", category: "survival",
    min: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "RTX 2060 / RX 5600 XT", ram: "16GB", storage: "200GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-10700K / Ryzen 5 3600", gpu: "RTX 3080 / RX 6800", ram: "32GB", storage: "200GB NVMe", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4080 / RX 7900 XTX", ram: "64GB", storage: "2TB NVMe", fps: "60@4K High" }
  },
  "satisfactory": {
    name: "Satisfactory", icon: "🏭", category: "sim",
    min: { cpu: "i5-3570K / Ryzen 3 1200", gpu: "GTX 760 / R9 270", ram: "8GB", storage: "20GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 / RX 5600 XT", ram: "16GB", storage: "20GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 6800 XT", ram: "32GB", storage: "512GB NVMe", fps: "60@1440p Ultra" }
  },
  "stalker-2": {
    name: "STALKER 2: Heart of Chornobyl", icon: "☢️", category: "horror",
    min: { cpu: "i7-7700K / Ryzen 5 1600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "160GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i9-11900K / Ryzen 7 5800X", gpu: "RTX 3070 Ti / RX 6800", ram: "32GB", storage: "160GB NVMe", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4080 / RX 7900 XTX", ram: "32GB", storage: "2TB NVMe", fps: "60@4K High" }
  },
  "mortal-kombat-1": {
    name: "Mortal Kombat 1", icon: "💥", category: "fighting",
    min: { cpu: "i5-6600 / Ryzen 3 3100", gpu: "GTX 980 / RX 580", ram: "8GB", storage: "100GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i7-8700K / Ryzen 5 3600X", gpu: "RTX 2070 / RX 5700 XT", ram: "8GB", storage: "100GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-12700K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "16GB", storage: "512GB NVMe", fps: "60@4K Ultra" }
  },
  "cyberpunk-2077-ultra": {
    name: "Cyberpunk 2077 — Path Tracing", icon: "🌃", category: "rpg",
    min: { cpu: "i7-12700 / Ryzen 7 7800X3D", gpu: "RTX 4070 Ti / RX 7900 XT", ram: "16GB", storage: "70GB NVMe", fps: "30@1440p PT Medium" },
    rec: { cpu: "i9-13900K / Ryzen 9 7950X3D", gpu: "RTX 4080 Super / RX 7900 XTX", ram: "32GB", storage: "70GB NVMe", fps: "60@1440p PT High" },
    above: { cpu: "Ryzen 9 9950X3D", gpu: "RTX 5090 32GB", ram: "64GB", storage: "2TB NVMe Gen5", fps: "60@4K Path Tracing" }
  },
  "pubg": {
    name: "PUBG: Battlegrounds", icon: "🎯", category: "fps",
    min: { cpu: "i5-4430 / Ryzen 3 1200", gpu: "GTX 960 / RX 470", ram: "8GB", storage: "40GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 / RX 5600 XT", ram: "16GB", storage: "40GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "256GB NVMe", fps: "144@1440p Competitive" }
  },
  "palworld": {
    name: "Palworld", icon: "🎮", category: "survival",
    min: { cpu: "i5-3570K / Ryzen 3 1200", gpu: "GTX 1050 / RX 560", ram: "8GB", storage: "40GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "40GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 7800 XT", ram: "32GB", storage: "512GB NVMe", fps: "60@1440p Ultra" }
  },
  "terraria": {
    name: "Terraria", icon: "⛏️", category: "survival",
    min: { cpu: "i3-3210 / Ryzen 3 1200", gpu: "Intel HD 3000 / Radeon HD 5000", ram: "4GB", storage: "1GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 3 1300X", gpu: "GTX 650 / R7 250", ram: "8GB", storage: "1GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "16GB NVMe", fps: "144@1440p Max" }
  },
  "path-of-exile-2": {
    name: "Path of Exile 2", icon: "🗡️", category: "rpg",
    min: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "100GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-10700 / Ryzen 5 5600X", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "100GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4070 Ti / RX 7800 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@1440p Ultra" }
  },
  "rust": {
    name: "Rust", icon: "🔧", category: "survival",
    min: { cpu: "i5-6600K / Ryzen 5 1600", gpu: "GTX 980 / RX 580", ram: "16GB", storage: "25GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-9700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "24GB", storage: "25GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 6800 XT", ram: "32GB", storage: "512GB NVMe", fps: "144@1440p Ultra" }
  },
  "dead-by-daylight": {
    name: "Dead by Daylight", icon: "🔪", category: "horror",
    min: { cpu: "i3-4170 / Ryzen 3 1200", gpu: "GTX 460 / HD 6850", ram: "8GB", storage: "50GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "GTX 1050 Ti / RX 470", ram: "8GB", storage: "50GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-9700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "128GB NVMe", fps: "144@1440p Max" }
  },
  "lethal-company": {
    name: "Lethal Company", icon: "☠️", category: "horror",
    min: { cpu: "i5-7400 / Ryzen 3 1200", gpu: "GTX 1050 / RX 560", ram: "8GB", storage: "1GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "GTX 1060 / RX 580", ram: "8GB", storage: "1GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "16GB NVMe", fps: "144@1440p Max" }
  },
  "stardew-valley": {
    name: "Stardew Valley", icon: "🌾", category: "sim",
    min: { cpu: "i3-2100 / Ryzen 3 1200", gpu: "Intel HD 2000 / Radeon HD 5000", ram: "4GB", storage: "1GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 3 1300X", gpu: "GTX 650 / R7 250", ram: "8GB", storage: "1GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "16GB NVMe", fps: "144@1440p Max" }
  },
  "destiny-2": {
    name: "Destiny 2", icon: "🌌", category: "fps",
    min: { cpu: "i5-2400 / Ryzen 3 1200", gpu: "GTX 660 / HD 7850", ram: "6GB", storage: "105GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-7400 / Ryzen 5 1600", gpu: "GTX 1060 / RX 580", ram: "8GB", storage: "105GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-10700K / Ryzen 5 5600X", gpu: "RTX 3060 / RX 6600 XT", ram: "16GB", storage: "512GB NVMe", fps: "144@1440p Competitive" }
  },
  "warframe": {
    name: "Warframe", icon: "🤖", category: "fps",
    min: { cpu: "i5-750 / Ryzen 3 1200", gpu: "GTX 460 / HD 5770", ram: "4GB", storage: "50GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-4570 / Ryzen 5 1600", gpu: "GTX 960 / RX 470", ram: "8GB", storage: "50GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "256GB NVMe", fps: "144@1440p Max" }
  },
  "the-witcher-3": {
    name: "The Witcher 3: Wild Hunt", icon: "🐺", category: "rpg",
    min: { cpu: "i5-2500K / Ryzen 3 1200", gpu: "GTX 660 / HD 7870", ram: "6GB", storage: "50GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-3770 / Ryzen 5 1600", gpu: "GTX 770 / R9 290", ram: "8GB", storage: "50GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-9900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "512GB NVMe", fps: "60@4K Ultra RT" }
  },
  "monster-hunter-wilds": {
    name: "Monster Hunter Wilds", icon: "🐉", category: "rpg",
    min: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "140GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-11700 / Ryzen 5 5600X", gpu: "RTX 2060 Super / RX 6600 XT", ram: "16GB", storage: "140GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4070 Ti / RX 7800 XT", ram: "32GB", storage: "1TB NVMe", fps: "60@1440p Ultra" }
  },
  "rocket-league": {
    name: "Rocket League", icon: "🚗", category: "racing",
    min: { cpu: "i5-2300 / Ryzen 3 1200", gpu: "GTX 460 / HD 6870", ram: "4GB", storage: "20GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-6400 / Ryzen 5 1400", gpu: "GTX 960 / RX 470", ram: "8GB", storage: "20GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "64GB NVMe", fps: "144@1440p Max" }
  },
  "cities-skylines-2": {
    name: "Cities: Skylines II", icon: "🏙️", category: "sim",
    min: { cpu: "i7-6700K / Ryzen 5 2600X", gpu: "GTX 970 / RX 480", ram: "16GB", storage: "60GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-9700K / Ryzen 5 5600X", gpu: "RTX 2060 / RX 5700", ram: "32GB", storage: "60GB SSD", fps: "30@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4080 / RX 7900 XT", ram: "64GB", storage: "1TB NVMe", fps: "60@1440p High" }
  },
  "euro-truck-2": {
    name: "Euro Truck Simulator 2", icon: "🚛", category: "sim",
    min: { cpu: "i3-2100 / Ryzen 3 1200", gpu: "GTX 460 / HD 5670", ram: "4GB", storage: "12GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-6400 / Ryzen 5 1400", gpu: "GTX 760 / R9 270", ram: "8GB", storage: "12GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-9700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "64GB NVMe", fps: "60@1440p Ultra" }
  },
  "team-fortress-2": {
    name: "Team Fortress 2", icon: "🎩", category: "fps",
    min: { cpu: "i3-2100 / Ryzen 3 1200", gpu: "Intel HD 3000 / Radeon HD 5000", ram: "4GB", storage: "15GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 3 1300X", gpu: "GTX 650 / R7 250", ram: "8GB", storage: "15GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "64GB NVMe", fps: "144@1440p Max" }
  },
  "left-4-dead-2": {
    name: "Left 4 Dead 2", icon: "🧟", category: "horror",
    min: { cpu: "i3-2100 / Ryzen 3 1200", gpu: "Intel HD 3000 / Radeon HD 5000", ram: "4GB", storage: "13GB SSD", fps: "60@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 3 1300X", gpu: "GTX 650 / R7 250", ram: "8GB", storage: "13GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "64GB NVMe", fps: "144@1440p Max" }
  },
  "phasmophobia": {
    name: "Phasmophobia", icon: "👻", category: "horror",
    min: { cpu: "i5-4590 / Ryzen 3 1200", gpu: "GTX 970 / RX 470", ram: "8GB", storage: "25GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "25GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-9900K / Ryzen 5 5600X", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "128GB NVMe", fps: "144@1440p Ultra" }
  },
  "valheim": {
    name: "Valheim", icon: "⚔️", category: "survival",
    min: { cpu: "i5-3570K / Ryzen 3 1200", gpu: "GTX 750 / R7 260", ram: "8GB", storage: "1GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-6600 / Ryzen 5 1600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "1GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-9700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "64GB NVMe", fps: "60@1440p Ultra" }
  },
  "overwatch-2": {
    name: "Overwatch 2", icon: "🎯", category: "fps",
    min: { cpu: "i3-540 / Ryzen 3 1200", gpu: "Intel HD 4400 / Radeon HD 5000", ram: "6GB", storage: "50GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-6400 / Ryzen 5 1400", gpu: "GTX 960 / RX 470", ram: "8GB", storage: "50GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "128GB NVMe", fps: "144@1440p Competitive" }
  },
  "payday-2": {
    name: "PAYDAY 2", icon: "💰", category: "horror",
    min: { cpu: "i5-2300 / Ryzen 3 1200", gpu: "GTX 460 / HD 5770", ram: "4GB", storage: "45GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 3 1300X", gpu: "GTX 660 / R7 260", ram: "8GB", storage: "45GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "128GB NVMe", fps: "144@1440p Max" }
  },
  "7-days-to-die": {
    name: "7 Days to Die", icon: "🧟", category: "survival",
    min: { cpu: "i5-2400 / Ryzen 3 1200", gpu: "GTX 460 / HD 5770", ram: "6GB", storage: "8GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-4770K / Ryzen 5 1600", gpu: "GTX 960 / RX 470", ram: "12GB", storage: "8GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-9900K / Ryzen 5 5600X", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "64GB NVMe", fps: "60@1440p High" }
  },
  "civilization-7": {
    name: "Sid Meier's Civilization VII", icon: "🏛️", category: "sim",
    min: { cpu: "i5-4690 / Ryzen 3 1200", gpu: "GTX 960 / RX 470", ram: "8GB", storage: "30GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "30GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-13900K / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 6800 XT", ram: "32GB", storage: "256GB NVMe", fps: "60@1440p Ultra" }
  },
  "dayz": {
    name: "DayZ", icon: "🧟", category: "survival",
    min: { cpu: "i5-4430 / Ryzen 3 1200", gpu: "GTX 760 / R9 270", ram: "8GB", storage: "25GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "25GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-9900K / Ryzen 7 5800X3D", gpu: "RTX 2070 / RX 5700", ram: "16GB", storage: "128GB NVMe", fps: "60@1440p Ultra" }
  },
  "grand-theft-auto-5": {
    name: "Grand Theft Auto V", icon: "🚗", category: "action",
    min: { cpu: "i5-3470 / Ryzen 3 1200", gpu: "GTX 660 / HD 7870", ram: "8GB", storage: "120GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-4770K / Ryzen 5 1500X", gpu: "GTX 1060 / RX 480", ram: "8GB", storage: "120GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-9900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "512GB NVMe", fps: "60@4K Ultra" }
  },
  "lost-ark": {
    name: "Lost Ark", icon: "⚓", category: "mmo",
    min: { cpu: "i3-6300 / Ryzen 3 1200", gpu: "GTX 560 / HD 6870", ram: "8GB", storage: "60GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "GTX 1050 Ti / RX 470", ram: "16GB", storage: "60GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-10700K / Ryzen 5 5600X", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "256GB NVMe", fps: "144@1440p Max" }
  },
  "new-world": {
    name: "New World: Aeternum", icon: "🏹", category: "mmo",
    min: { cpu: "i5-6600K / Ryzen 5 1600", gpu: "GTX 970 / RX 470", ram: "8GB", storage: "100GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "100GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3070 / RX 6800", ram: "32GB", storage: "512GB NVMe", fps: "60@1440p Ultra" }
  },
  "final-fantasy-14": {
    name: "Final Fantasy XIV Online", icon: "✨", category: "mmo",
    min: { cpu: "i5-2500 / Ryzen 3 1200", gpu: "GTX 660 / R9 270", ram: "4GB", storage: "80GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-6700 / Ryzen 5 1600", gpu: "GTX 970 / RX 480", ram: "8GB", storage: "80GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 7800X3D", gpu: "RTX 4070 / RX 6800 XT", ram: "32GB", storage: "512GB NVMe", fps: "144@1440p Ultra" }
  },
  "project-zomboid": {
    name: "Project Zomboid", icon: "🧟", category: "survival",
    min: { cpu: "i5-2400 / Ryzen 3 1200", gpu: "Intel HD 3000 / Radeon HD 5000", ram: "4GB", storage: "5GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 3 1300X", gpu: "GTX 650 / R7 250", ram: "8GB", storage: "5GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "32GB NVMe", fps: "60@1440p Ultra" }
  },
  "sons-of-the-forest": {
    name: "Sons of the Forest", icon: "🌲", category: "survival",
    min: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "GTX 1060 / RX 580", ram: "12GB", storage: "20GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700K / Ryzen 5 3600X", gpu: "RTX 2060 / RX 5700 XT", ram: "16GB", storage: "20GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3080 / RX 6800 XT", ram: "32GB", storage: "256GB NVMe", fps: "60@1440p Ultra" }
  },
  "hunt-showdown": {
    name: "Hunt: Showdown 1896", icon: "🔫", category: "fps",
    min: { cpu: "i5-4590 / Ryzen 3 1200", gpu: "GTX 660 / HD 7850", ram: "8GB", storage: "40GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-6700 / Ryzen 5 1600", gpu: "GTX 970 / RX 480", ram: "16GB", storage: "40GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-9900K / Ryzen 5 5600X", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "128GB NVMe", fps: "60@1440p Ultra" }
  },
  "tcg-card-shop-sim": {
    name: "TCG Card Shop Simulator", icon: "🃏", category: "sim",
    min: { cpu: "i3-2100 / Ryzen 3 1200", gpu: "Intel HD 3000 / Radeon HD 5000", ram: "4GB", storage: "2GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 3 1300X", gpu: "GTX 650 / R7 250", ram: "8GB", storage: "2GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "64GB NVMe", fps: "144@1440p Max" }
  },
  "enlisted": {
    name: "Enlisted", icon: "🎖️", category: "fps",
    min: { cpu: "i3-8100 / Ryzen 3 1200", gpu: "GTX 660 / R7 370", ram: "8GB", storage: "45GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-8400 / Ryzen 5 2600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "45GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-10700K / Ryzen 5 5600X", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "128GB NVMe", fps: "144@1440p Max" }
  },
  "mount-blade-2": {
    name: "Mount & Blade II: Bannerlord", icon: "⚔️", category: "rpg",
    min: { cpu: "i5-4460 / Ryzen 3 1200", gpu: "GTX 660 / R9 270", ram: "8GB", storage: "60GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "60GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-9900K / Ryzen 7 5800X3D", gpu: "RTX 2070 / RX 5700", ram: "32GB", storage: "256GB NVMe", fps: "60@1440p Ultra" }
  },
  "dont-starve-together": {
    name: "Don't Starve Together", icon: "🔥", category: "survival",
    min: { cpu: "i3-2100 / Ryzen 3 1200", gpu: "Intel HD 3000 / Radeon HD 5000", ram: "4GB", storage: "1GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 3 1300X", gpu: "GTX 650 / R7 250", ram: "8GB", storage: "1GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "16GB NVMe", fps: "144@1440p Max" }
  },
  "smite-2": {
    name: "SMITE 2", icon: "⚡", category: "fighting",
    min: { cpu: "i5-3470 / Ryzen 3 1200", gpu: "GTX 660 / HD 7870", ram: "8GB", storage: "40GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-6400 / Ryzen 5 1400", gpu: "GTX 960 / RX 470", ram: "8GB", storage: "40GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700K / Ryzen 5 3600", gpu: "RTX 2060 / RX 5700", ram: "16GB", storage: "128GB NVMe", fps: "144@1440p Competitive" }
  },
  "naraka-bladepoint": {
    name: "NARAKA: BLADEPOINT", icon: "🗡️", category: "fighting",
    min: { cpu: "i5-6400 / Ryzen 3 1200", gpu: "GTX 1050 Ti / RX 470", ram: "8GB", storage: "50GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "RTX 2060 Super / RX 5700", ram: "16GB", storage: "50GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-12900K / Ryzen 7 5800X3D", gpu: "RTX 3070 / RX 6800", ram: "32GB", storage: "256GB NVMe", fps: "144@1440p Competitive" }
  },
  "dota-2": {
    name: "Dota 2", icon: "⚔️", category: "fighting",
    min: { cpu: "i3-2100 / Ryzen 3 1200", gpu: "Intel HD 3000 / Radeon HD 5000", ram: "4GB", storage: "15GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i5-4460 / Ryzen 3 1300X", gpu: "GTX 650 / R7 250", ram: "8GB", storage: "15GB SSD", fps: "60@1080p High" },
    above: { cpu: "i7-8700 / Ryzen 5 3600", gpu: "GTX 1060 / RX 580", ram: "16GB", storage: "64GB NVMe", fps: "144@1440p Max" }
  },
  "arma-3": {
    name: "Arma 3", icon: "🎖️", category: "sim",
    min: { cpu: "i5-4460 / Ryzen 3 1200", gpu: "GTX 660 / HD 7800", ram: "8GB", storage: "45GB SSD", fps: "30@1080p Low" },
    rec: { cpu: "i7-6700 / Ryzen 5 1600", gpu: "GTX 1060 / RX 480", ram: "16GB", storage: "45GB SSD", fps: "60@1080p High" },
    above: { cpu: "i9-9900K / Ryzen 7 5800X3D", gpu: "RTX 2070 / RX 5700", ram: "32GB", storage: "256GB NVMe", fps: "60@1440p Max" }
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
