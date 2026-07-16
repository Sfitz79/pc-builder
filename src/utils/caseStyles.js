const MESH_SVG = `<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
  <rect x="10" y="6" width="60" height="68" rx="3" stroke="currentColor" stroke-width="1.5"/>
  <rect x="22" y="3" width="36" height="6" rx="2" fill="currentColor" opacity="0.25"/>
  <rect x="14" y="18" width="52" height="12" rx="2" fill="currentColor" opacity="0.08"/>
  <rect x="14" y="18" width="52" height="12" rx="2" stroke="currentColor" stroke-width="0.6" opacity="0.2"/>
  <line x1="18" y1="22" x2="62" y2="22" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="18" y1="26" x2="62" y2="26" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="14" y1="19" x2="14" y2="29" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="20" y1="19" x2="20" y2="29" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="26" y1="19" x2="26" y2="29" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="32" y1="19" x2="32" y2="29" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="38" y1="19" x2="38" y2="29" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="44" y1="19" x2="44" y2="29" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="50" y1="19" x2="50" y2="29" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="56" y1="19" x2="56" y2="29" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <line x1="62" y1="19" x2="62" y2="29" stroke="currentColor" stroke-width="0.5" opacity="0.15"/>
  <rect x="14" y="34" width="22" height="14" rx="1.5" stroke="currentColor" stroke-width="0.8" opacity="0.4"/>
  <rect x="40" y="34" width="26" height="14" rx="1.5" stroke="currentColor" stroke-width="0.8" opacity="0.4"/>
  <rect x="14" y="52" width="52" height="18" rx="2" fill="currentColor" opacity="0.08"/>
  <rect x="14" y="52" width="52" height="18" rx="2" stroke="currentColor" stroke-width="0.6" opacity="0.15"/>
  <path d="M12 6 L12 72 L22 72 L22 6 Z" fill="currentColor" opacity="0.06"/>
  <path d="M58 6 L58 72 L68 72 L68 6 Z" fill="currentColor" opacity="0.06"/>
  <rect x="24" y="16" width="32" height="3" rx="1" fill="currentColor" opacity="0.08"/>
  <rect x="24" y="50" width="32" height="3" rx="1" fill="currentColor" opacity="0.08"/>
</svg>`;

const FISH_TANK_SVG = `<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
  <rect x="8" y="6" width="64" height="68" rx="3" stroke="currentColor" stroke-width="1.5"/>
  <rect x="18" y="3" width="44" height="6" rx="2" fill="currentColor" opacity="0.25"/>
  <rect x="8" y="6" width="64" height="68" rx="3" stroke="currentColor" stroke-width="0.8" opacity="0.15" stroke-dasharray="3 3"/>
  <rect x="11" y="14" width="58" height="18" rx="2" stroke="currentColor" stroke-width="0.7" opacity="0.3"/>
  <rect x="11" y="14" width="58" height="18" rx="2" fill="currentColor" opacity="0.04"/>
  <rect x="11" y="36" width="26" height="16" rx="2" stroke="currentColor" stroke-width="0.7" opacity="0.35"/>
  <rect x="41" y="36" width="28" height="16" rx="2" stroke="currentColor" stroke-width="0.7" opacity="0.35"/>
  <rect x="11" y="56" width="58" height="14" rx="2" stroke="currentColor" stroke-width="0.6" opacity="0.2"/>
  <rect x="11" y="56" width="58" height="14" rx="2" fill="currentColor" opacity="0.04"/>
  <rect x="72" y="12" width="4" height="58" rx="1" fill="currentColor" opacity="0.08"/>
  <rect x="4" y="12" width="4" height="58" rx="1" fill="currentColor" opacity="0.08"/>
  <line x1="40" y1="14" x2="40" y2="32" stroke="currentColor" stroke-width="0.5" opacity="0.08" stroke-dasharray="2 2"/>
  <line x1="40" y1="36" x2="40" y2="52" stroke="currentColor" stroke-width="0.5" opacity="0.08" stroke-dasharray="2 2"/>
  <rect x="28" y="20" width="6" height="4" rx="0.8" fill="#00eaff" opacity="0.15"/>
  <rect x="38" y="22" width="4" height="4" rx="0.8" fill="#ff005e" opacity="0.12"/>
  <rect x="48" y="20" width="6" height="4" rx="0.8" fill="#00eaff" opacity="0.1"/>
  <rect x="18" y="40" width="6" height="4" rx="0.8" fill="#ff005e" opacity="0.1"/>
  <rect x="28" y="44" width="4" height="4" rx="0.8" fill="#00eaff" opacity="0.12"/>
  <rect x="48" y="42" width="6" height="4" rx="0.8" fill="#ff005e" opacity="0.1"/>
</svg>`;

const MINIMALIST_SVG = `<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
  <rect x="14" y="6" width="52" height="68" rx="2" stroke="currentColor" stroke-width="1.5"/>
  <rect x="26" y="3" width="28" height="6" rx="1" fill="currentColor" opacity="0.2"/>
  <rect x="18" y="14" width="44" height="20" rx="1.5" fill="currentColor" opacity="0.03"/>
  <rect x="18" y="14" width="44" height="20" rx="1.5" stroke="currentColor" stroke-width="0.5" opacity="0.12"/>
  <rect x="18" y="38" width="44" height="14" rx="1.5" fill="currentColor" opacity="0.03"/>
  <rect x="18" y="38" width="44" height="14" rx="1.5" stroke="currentColor" stroke-width="0.5" opacity="0.12"/>
  <rect x="18" y="56" width="44" height="14" rx="1.5" fill="currentColor" opacity="0.03"/>
  <rect x="18" y="56" width="44" height="14" rx="1.5" stroke="currentColor" stroke-width="0.5" opacity="0.12"/>
  <line x1="40" y1="14" x2="40" y2="70" stroke="currentColor" stroke-width="0.5" opacity="0.06"/>
  <rect x="14" y="6" width="52" height="68" rx="2" stroke="currentColor" stroke-width="0.5" opacity="0.08"/>
  <circle cx="40" cy="10" r="2.5" fill="currentColor" opacity="0.08"/>
  <rect x="22" y="16" width="36" height="3" rx="1" fill="currentColor" opacity="0.06"/>
  <rect x="22" y="22" width="28" height="3" rx="1" fill="currentColor" opacity="0.04"/>
  <rect x="22" y="40" width="36" height="3" rx="1" fill="currentColor" opacity="0.06"/>
  <rect x="22" y="46" width="20" height="3" rx="1" fill="currentColor" opacity="0.04"/>
  <rect x="22" y="58" width="36" height="3" rx="1" fill="currentColor" opacity="0.06"/>
  <rect x="22" y="64" width="24" height="3" rx="1" fill="currentColor" opacity="0.04"/>
</svg>`;

const WOOD_GRAIN_SVG = `<svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
  <rect x="10" y="6" width="60" height="68" rx="3" stroke="currentColor" stroke-width="1.5"/>
  <rect x="22" y="3" width="36" height="6" rx="2" fill="currentColor" opacity="0.25"/>
  <rect x="10" y="14" width="16" height="56" rx="1.5" fill="currentColor" opacity="0.06"/>
  <rect x="10" y="14" width="16" height="56" rx="1.5" stroke="currentColor" stroke-width="0.6" opacity="0.2"/>
  <line x1="14" y1="20" x2="22" y2="20" stroke="#8B6914" stroke-width="1" opacity="0.25" stroke-linecap="round"/>
  <line x1="14" y1="26" x2="22" y2="26" stroke="#8B6914" stroke-width="1" opacity="0.2" stroke-linecap="round"/>
  <line x1="14" y1="32" x2="22" y2="32" stroke="#8B6914" stroke-width="0.8" opacity="0.15" stroke-linecap="round"/>
  <line x1="14" y1="38" x2="22" y2="38" stroke="#8B6914" stroke-width="1" opacity="0.2" stroke-linecap="round"/>
  <line x1="14" y1="44" x2="22" y2="44" stroke="#8B6914" stroke-width="0.8" opacity="0.15" stroke-linecap="round"/>
  <line x1="14" y1="50" x2="22" y2="50" stroke="#8B6914" stroke-width="1" opacity="0.2" stroke-linecap="round"/>
  <line x1="14" y1="56" x2="22" y2="56" stroke="#8B6914" stroke-width="0.8" opacity="0.15" stroke-linecap="round"/>
  <line x1="14" y1="62" x2="22" y2="62" stroke="#8B6914" stroke-width="1" opacity="0.2" stroke-linecap="round"/>
  <rect x="29" y="14" width="38" height="22" rx="2" stroke="currentColor" stroke-width="0.7" opacity="0.3"/>
  <rect x="29" y="14" width="38" height="22" rx="2" fill="currentColor" opacity="0.03"/>
  <rect x="29" y="40" width="38" height="26" rx="2" stroke="currentColor" stroke-width="0.7" opacity="0.3"/>
  <rect x="29" y="40" width="38" height="26" rx="2" fill="currentColor" opacity="0.03"/>
  <rect x="36" y="20" width="16" height="3" rx="1" fill="currentColor" opacity="0.06"/>
  <rect x="36" y="28" width="24" height="3" rx="1" fill="currentColor" opacity="0.04"/>
  <rect x="36" y="46" width="22" height="3" rx="1" fill="currentColor" opacity="0.06"/>
  <rect x="36" y="54" width="14" height="3" rx="1" fill="currentColor" opacity="0.04"/>
  <rect x="10" y="14" width="2" height="56" fill="#8B6914" opacity="0.12"/>
</svg>`;

const CASE_STYLE_IMAGES = {
  "high-airflow-mesh": "/mesh front.png",
  "fish-tank": "/fish tank.png",
  "minimalist-office": "/office pc case.png",
  "wood-grain": "/lian_li_dan_cases_a3-matx_black_wood_edition_haupt_3000px_1.png",
};

const CASE_STYLES = [
  {
    id: "high-airflow-mesh",
    label: "High-Airflow (Mesh)",
    focus: "Peak cooling performance",
    description: "Designed for peak cooling performance with mesh front, top, and side panels. Great for high-wattage gaming hardware.",
    pros: ["Maximum cooling efficiency", "Ideal for high-end GPUs", "Easy dust filter access"],
    cons: ["Higher noise bleed", "More frequent dusting needed"],
    svg: MESH_SVG,
    match: (c) => {
      const sp = String(c.side_panel || "").toLowerCase();
      const name = String(c.name || "").toLowerCase();
      const type = String(c.type || "").toLowerCase();
      if (sp === "mesh") return true;
      if (name.includes("mesh") || name.includes("airflow") || name.includes("air ")) return true;
      if (type.includes("mesh")) return true;
      return false;
    }
  },
  {
    id: "fish-tank",
    label: "Fish Tank Style",
    focus: "Showcase your build",
    description: "Dual-chamber layouts with edge-to-edge tempered glass and no center support pillar. Beautifully displays components but runs warmer than mesh designs.",
    pros: ["Stunning component visibility", "Clean cable management", "Premium aesthetic"],
    cons: ["Higher internal temps", "Heavier and bulkier", "More expensive"],
    svg: FISH_TANK_SVG,
    match: (c) => {
      const name = String(c.name || "").toLowerCase();
      if (name.includes("o11") || name.includes("o11d")) return true;
      if (name.includes("dynamic") && name.includes("evo")) return true;
      if (name.includes("vision") && !name.includes("nvision")) return true;
      if (name.includes("h9") || name.includes("h6")) return true;
      if (name.includes("y70") || name.includes("y60")) return true;
      if (name.includes("antec c8") || name.includes("antec c5")) return true;
      if (name.includes("king 95")) return true;
      if (name.includes("d300") || name.includes("d400")) return true;
      if (name.includes("panorama") || name.includes("panoramic")) return true;
      return false;
    }
  },
  {
    id: "minimalist-office",
    label: "Minimalist / Office",
    focus: "Clean & professional",
    description: "Clean, sleek lines with solid or acrylic panels and muted colours. Ideal for professional workspaces or those who prefer understated aesthetics.",
    pros: ["Professional appearance", "Better noise dampening", "Subtle & elegant"],
    cons: ["Limited airflow", "Less visual flair", "Fewer fan mounts"],
    svg: MINIMALIST_SVG,
    match: (c) => {
      const sp = String(c.side_panel || "").toLowerCase();
      const name = String(c.name || "").toLowerCase();
      if ((sp.includes("acrylic") || sp === "solid") && !sp.includes("mesh") && !sp.includes("tempered")) {
        if (name.includes("define") || name.includes("silent") || name.includes("base") ||
            name.includes("office") || name.includes("pure") || name.includes("quiet") ||
            name.includes("dark") || name.includes("shadow") || name.includes("compact")) {
          return true;
        }
      }
      if (name.includes("define") || name.includes("silent base")) return true;
      return false;
    }
  },
  {
    id: "wood-grain",
    label: "Wood Grain / Furniture",
    focus: "Natural materials aesthetic",
    description: "Features natural materials like wood slats on the front panel, blending high-end hardware with home decor for a unique warm aesthetic.",
    pros: ["Unique furniture look", "Warm natural aesthetic", "Conversation piece"],
    cons: ["Limited availability", "Premium pricing", "Slightly restricted airflow"],
    svg: WOOD_GRAIN_SVG,
    match: (c) => {
      const name = String(c.name || "").toLowerCase();
      if (name.includes("north") || name.includes("wood") || name.includes("walnut") || name.includes("oak")) return true;
      return false;
    }
  }
];

export function getCaseStyles() {
  return CASE_STYLES;
}

export function getCaseStyleImage(styleId) {
  return CASE_STYLE_IMAGES[styleId] || null;
}

export function inferCaseStyle(caseItem) {
  if (!caseItem) return "fish-tank";
  for (const style of CASE_STYLES) {
    if (style.match(caseItem)) return style.id;
  }
  return "fish-tank";
}

export function filterCasesByStyle(cases, styleId) {
  if (!styleId || styleId === "any") return cases;
  const style = CASE_STYLES.find(s => s.id === styleId);
  if (!style) return cases;
  return cases.filter(c => style.match(c));
}

export function filterCasesByColor(cases, colorValue) {
  if (!colorValue || colorValue === "any") return cases;
  return cases.filter(c => {
    const cColor = String(c.color || "").toLowerCase();
    return cColor.includes(colorValue.toLowerCase());
  });
}
