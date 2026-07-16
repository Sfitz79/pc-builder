export const BUILDER_CATEGORIES = [
  { id: "case", label: "Case", file: "case.csv", icon: "case" },
  { id: "cpu", label: "CPU", file: "cpu.csv", icon: "cpu" },
  { id: "cooler", label: "CPU Cooler", file: "cooler.csv", icon: "cooler" },
  { id: "motherboard", label: "Motherboard", file: "motherboard.csv", icon: "motherboard" },
  { id: "ram", label: "RAM", file: "ram.csv", icon: "ram" },
  { id: "gpu", label: "GPU", file: "gpu.csv", icon: "gpu" },
  { id: "storage", label: "Storage", file: "storage.csv", icon: "storage", multi: true },
  { id: "psu", label: "Power Supply", file: "power-supply.csv", icon: "psu" },
  { id: "os", label: "Operating System", file: "os.csv", icon: "os" },
  { id: "wireless-network-card", label: "WiFi / Network Card", file: "wireless-network-card.csv", icon: "network" },
  { id: "monitor", label: "Monitor", file: "monitor.csv", icon: "monitor", multi: true },
];

export const SUBCATEGORY_GROUPS = [
  {
    label: "Expansion Cards / Networking",
    categories: [
      { id: "sound-card", label: "Sound Card", file: "sound-card.csv", icon: "sound" },
      { id: "wired-network-card", label: "Wired Network Card", file: "wired-network-card.csv", icon: "network" },
    ]
  },
  {
    label: "Peripherals",
    categories: [
      { id: "keyboard", label: "Keyboard", file: "keyboard.csv", icon: "keyboard" },
      { id: "mouse", label: "Mouse", file: "mouse.csv", icon: "mouse" },
      { id: "speakers", label: "Speakers / Audio", file: "speakers.csv", icon: "speakers" },
      { id: "headphones", label: "Headphones", file: "headphones.csv", icon: "headphones" },
      { id: "webcam", label: "Webcam", file: "webcam.csv", icon: "webcam" },
    ]
  },
  {
    label: "Cooling / Accessories",
    categories: [
      { id: "case-fan", label: "Case Fan", file: "case-fan.csv", icon: "fan", multi: true },
      { id: "thermal-paste", label: "Thermal Paste", file: "thermal-paste.csv", icon: "paste" },
      { id: "fan-controller", label: "Fan Controller", file: "fan-controller.csv", icon: "fan" },
    ]
  },
  {
    label: "Storage / Drives",
    categories: [
      { id: "external-hard-drive", label: "External Hard Drive", file: "external-hard-drive.csv", icon: "storage" },
      { id: "optical-drive", label: "Optical Drive", file: "optical-drive.csv", icon: "disc" },
    ]
  },
  {
    label: "Power Protection",
    categories: [
      { id: "ups", label: "UPS", file: "ups.csv", icon: "power" },
    ]
  },
];

export const ADDON_GROUPS = [
  {
    label: "Streaming & Content Creation",
    categories: [
      { id: "streaming", label: "Streaming Equipment", file: "Streaming.csv", icon: "webcam" },
    ]
  },
  {
    label: "Sim Racing & Flight",
    categories: [
      { id: "racing-simulation", label: "Racing Simulation", file: "racing-simulation.csv", icon: "case" },
      { id: "flight-simulation", label: "Flight Simulation", file: "flight-simulation.csv", icon: "case" },
    ]
  },
  {
    label: "Gaming Peripherals",
    categories: [
      { id: "game-controllers", label: "Game Controllers", file: "game-controllers.csv", icon: "mouse" },
    ]
  },
  {
    label: "Cables & Accessories",
    categories: [
      { id: "cables-and-accessories", label: "Cables & Accessories", file: "cables-and-accessories.csv", icon: "network" },
    ]
  },
];

export const MULTI_SELECT_CATEGORIES = new Set(
  BUILDER_CATEGORIES.filter(c => c.multi).map(c => c.id).concat(
    SUBCATEGORY_GROUPS.flatMap(g => g.categories.filter(c => c.multi).map(c => c.id))
  )
);

export function isMultiSelect(categoryId) {
  return MULTI_SELECT_CATEGORIES.has(categoryId);
}

export function getAllCategories() {
  const all = [
    ...BUILDER_CATEGORIES,
    ...SUBCATEGORY_GROUPS.flatMap(g => g.categories),
    ...ADDON_GROUPS.flatMap(g => g.categories),
  ];
  return all;
}

export function findCategory(categoryId) {
  return getAllCategories().find(c => c.id === categoryId);
}

export function getComponentIconSVG(iconName) {
  const paths = {
    cpu: '<rect x="7" y="7" width="34" height="34" rx="6"/><rect x="16" y="16" width="16" height="16" rx="3"/><path d="M24 2v5M24 41v5M2 24h5M41 24h5M10 2v4M38 2v4M10 42v4M38 42v4"/>',
    motherboard: '<rect x="5" y="5" width="38" height="38" rx="5"/><rect x="11" y="11" width="14" height="14" rx="2"/><path d="M29 12h8M29 18h8M11 31h26M11 36h18"/>',
    cooler: '<circle cx="24" cy="24" r="14"/><circle cx="24" cy="24" r="4"/><path d="M24 10v6M24 32v6M10 24h6M32 24h6M14 14l4 4M30 30l4 4M14 34l4-4M30 18l4-4"/>',
    ram: '<rect x="4" y="16" width="40" height="14" rx="3"/><path d="M10 16v14M16 16v14M22 16v14M28 16v14M34 16v14M8 34h32"/>',
    storage: '<rect x="8" y="6" width="30" height="36" rx="4"/><circle cx="23" cy="30" r="4"/><path d="M16 14h14"/>',
    gpu: '<rect x="4" y="12" width="40" height="24" rx="5"/><circle cx="18" cy="24" r="6"/><path d="M30 20h10M30 24h10M30 28h10"/>',
    case: '<rect x="12" y="4" width="24" height="40" rx="4"/><circle cx="24" cy="14" r="2"/><path d="M18 22h12M18 28h12M18 34h12"/>',
    psu: '<rect x="6" y="8" width="36" height="32" rx="4"/><circle cx="18" cy="24" r="8"/><path d="M30 16h8M30 22h8M30 28h8"/>',
    os: '<rect x="6" y="8" width="36" height="30" rx="4"/><path d="M6 16h36M18 8v30"/>',
    monitor: '<rect x="6" y="8" width="36" height="24" rx="3"/><path d="M18 40h12M24 32v8"/>',
    sound: '<path d="M8 30h8l10 8V10L16 18H8z"/><path d="M32 18c3 2 4 10 0 12M36 14c5 4 6 16 0 20"/>',
    network: '<circle cx="24" cy="28" r="2"/><path d="M10 30a14 14 0 0 1 28 0M14 24a10 10 0 0 1 20 0M18 18a6 6 0 0 1 12 0"/>',
    headphones: '<path d="M12 26v-2a12 12 0 0 1 24 0v2"/><rect x="9" y="24" width="6" height="12" rx="2"/><rect x="33" y="24" width="6" height="12" rx="2"/>',
    keyboard: '<rect x="4" y="12" width="40" height="24" rx="4"/><path d="M10 20h4M16 20h4M22 20h4M28 20h4M34 20h4M10 28h28"/>',
    mouse: '<rect x="14" y="6" width="20" height="36" rx="10"/><path d="M24 10v8"/>',
    webcam: '<rect x="6" y="12" width="36" height="24" rx="5"/><circle cx="24" cy="24" r="7"/><path d="M14 12l4-4h12l4 4"/>',
    fan: '<circle cx="24" cy="24" r="4"/><path d="M24 8c6 0 8 6 4 10M40 24c0 6-6 8-10 4M24 40c-6 0-8-6-4-10M8 24c0-6 6-8 10-4"/><circle cx="24" cy="24" r="14"/>',
    disc: '<circle cx="24" cy="24" r="14"/><circle cx="24" cy="24" r="4"/>',
    power: '<rect x="8" y="8" width="32" height="32" rx="4"/><path d="M24 12v14M18 22h12M14 32h20"/>',
    paste: '<path d="M10 34l14-14 10 10-14 14z"/><path d="M28 16l6-6M16 28l-6 6"/>',
  };

  const path = paths[iconName] || paths.case;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="none" stroke="%2300eaff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
  return `data:image/svg+xml,${svg}`;
}
