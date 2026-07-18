import { create } from "zustand";
import { persist } from "zustand/middleware";

const REQUIRED_CATEGORIES = ['case', 'case-fan', 'cooler', 'cpu', 'motherboard', 'ram', 'ssd', 'psu', 'os'];

const DEFAULT_OS = {
  name: "Microsoft Windows 11 Pro Retail - Download 64-bit",
  price: "189.99",
  mode: "64",
  max_memory: "2048"
};

const MANDATORY_SERVICES = [
  { id: "build_service", name: "PCTG Build, Premium Cable Manage, Premium Test & Optimize", price: 150 },
  { id: "warranty", name: "2 Year Warranty & Free Tech Support / Remote Assistance", price: 0 },
  { id: "delivery", name: "Delivery By RM Special Delivery Insured", price: 50 },
  { id: "os", name: "Windows 11 Pro Retail", price: 35 }
];

const FULL_MANDATORY = MANDATORY_SERVICES.reduce((acc, s) => {
  acc[s.id] = { name: s.name, price: s.price };
  return acc;
}, {});

const PARTS_MANDATORY = {
  warranty: { name: "2 Year Warranty & Free Tech Support / Remote Assistance", price: 0 },
  os: { name: "Windows 11 Pro Retail", price: 35 },
  system_design: { name: "System Design Charge", price: 35 }
};

function ensureQty(item, qty = 1) {
  if (!item || typeof item !== "object") return item;
  return { ...item, qty: item.qty || qty };
}

function getItemQty(item) {
  if (!item) return 0;
  if (Array.isArray(item)) return item.reduce((s, i) => s + (i.qty || 1), 0);
  return item.qty || 1;
}

function getItemPriceTotal(item) {
  if (!item) return 0;
  if (Array.isArray(item)) {
    return item.reduce((s, i) => s + (parseFloat(i?.price) || 0) * (i.qty || 1), 0);
  }
  return (parseFloat(item?.price) || 0) * (item.qty || 1);
}

function getSelectionItems(selections) {
  const result = [];
  for (const [cat, val] of Object.entries(selections)) {
    if (Array.isArray(val)) {
      val.forEach(item => result.push({ cat, item }));
    } else if (val) {
      result.push({ cat, item: val });
    }
  }
  return result;
}

export const usePCStore = create(
  persist(
    (set, get) => ({
      selections: { os: ensureQty(DEFAULT_OS) },
      adminMode: false,
      buildType: "full",
      onBuildComplete: null,
      enabledAddons: {},

      mandatory: FULL_MANDATORY,

      setAdminMode: (mode) => set({ adminMode: mode }),

      setBuildType: (type) => {
        set({ buildType: type, mandatory: type === "parts" ? PARTS_MANDATORY : FULL_MANDATORY });
      },

      toggleAddon: (categoryId) => {
        set((state) => {
          const next = { ...state.enabledAddons };
          if (next[categoryId]) delete next[categoryId];
          else next[categoryId] = true;
          return { enabledAddons: next };
        });
      },

      setComponent: (category, item) => {
        set((state) => {
          const withQty = ensureQty(item);
          const newSelections = { ...state.selections, [category]: withQty };
          const gpuNeeded = (() => {
            const cpu = newSelections.cpu;
            const cpuHasIgpu = cpu && cpu.graphics && String(cpu.graphics).toLowerCase() !== "none";
            return !cpuHasIgpu;
          })();
          const allRequired = (gpuNeeded ? [...REQUIRED_CATEGORIES, 'gpu'] : REQUIRED_CATEGORIES).every(cat => newSelections[cat]);
          if (allRequired && state.onBuildComplete) {
            setTimeout(() => state.onBuildComplete(), 100);
          }
          return { selections: newSelections };
        });
      },

      toggleMultiComponent: (category, item) => {
        set((state) => {
          const current = state.selections[category];
          const withQty = ensureQty(item);
          let newVal;

          if (Array.isArray(current)) {
            const idx = current.findIndex(i => i.name === item.name);
            if (idx >= 0) {
              newVal = current.filter((_, i) => i !== idx);
              if (newVal.length === 0) newVal = undefined;
            } else {
              newVal = [...current, withQty];
            }
          } else {
            if (current?.name === item.name) {
              newVal = undefined;
            } else {
              newVal = [withQty];
            }
          }

          const newSelections = { ...state.selections };
          if (newVal === undefined) delete newSelections[category];
          else newSelections[category] = newVal;
          return { selections: newSelections };
        });
      },

      isMultiSelected: (category, itemName) => {
        const val = get().selections[category];
        if (Array.isArray(val)) return val.some(i => i.name === itemName);
        return val?.name === itemName;
      },

      setItemQty: (category, itemName, qty) => {
        set((state) => {
          const val = state.selections[category];
          const newSelections = { ...state.selections };

          if (Array.isArray(val)) {
            newSelections[category] = val.map(i =>
              i.name === itemName ? { ...i, qty: Math.max(1, qty) } : i
            );
          } else if (val?.name === itemName) {
            newSelections[category] = { ...val, qty: Math.max(1, qty) };
          }
          return { selections: newSelections };
        });
      },

      clearComponent: (category) => {
        set((state) => {
          const newSelections = { ...state.selections };
          delete newSelections[category];
          return { selections: newSelections };
        });
      },

      removeMultiItem: (category, itemName) => {
        set((state) => {
          const val = state.selections[category];
          const newSelections = { ...state.selections };
          if (Array.isArray(val)) {
            const remaining = val.filter(i => i.name !== itemName);
            if (remaining.length === 0) delete newSelections[category];
            else newSelections[category] = remaining;
          } else {
            delete newSelections[category];
          }
          return { selections: newSelections };
        });
      },

      resetBuild: () => set({ selections: { os: ensureQty(DEFAULT_OS) }, enabledAddons: {} }),

      getComponentsTotal: () => {
        const selections = get().selections;
        const buildType = get().buildType;
        return Object.entries(selections).reduce((sum, [cat, val]) => {
          if (cat === "os" && buildType === "full") return sum;
          return sum + getItemPriceTotal(val);
        }, 0);
      },

      getMandatoryTotal: () => {
        const mandatory = get().mandatory;
        return Object.values(mandatory).reduce((sum, item) => {
          const p = parseFloat(item?.price);
          return sum + (isNaN(p) ? 0 : p);
        }, 0);
      },

      getBundledPrice: () => {
        const componentsTotal = get().getComponentsTotal();
        const mandatoryTotal = get().getMandatoryTotal();
        const subtotal = componentsTotal + mandatoryTotal;
        const withSurcharge = subtotal * 1.03;
        return Math.ceil(withSurcharge);
      },

      getPriceBreakdown: () => {
        const selections = get().selections;
        const mandatory = get().mandatory;
        const buildType = get().buildType;
        const items = [];

        for (const { cat, item } of getSelectionItems(selections)) {
          if (cat === "os" && buildType === "full") continue;
          const qty = item.qty || 1;
          const price = parseFloat(item?.price) || 0;
          items.push({
            label: cat,
            name: item?.name || "—",
            price,
            qty,
            lineTotal: price * qty,
          });
        }
        for (const s of Object.values(mandatory)) {
          items.push({ label: "service", name: s.name, price: parseFloat(s.price) || 0, qty: 1, lineTotal: parseFloat(s.price) || 0 });
        }

        const componentsTotal = get().getComponentsTotal();
        const mandatoryTotal = get().getMandatoryTotal();
        const subtotal = componentsTotal + mandatoryTotal;
        const surcharge = subtotal * 0.03;
        const total = Math.ceil(subtotal * 1.03);

        return { items, componentsTotal, mandatoryTotal, subtotal, surcharge, total };
      },

      setOnBuildComplete: (callback) => set({ onBuildComplete: callback })
    }),
    {
      name: "pc-builder-storage",
      partialize: (state) => ({
        selections: state.selections,
        buildType: state.buildType,
        adminMode: state.adminMode,
        enabledAddons: state.enabledAddons,
      })
    }
  )
);
