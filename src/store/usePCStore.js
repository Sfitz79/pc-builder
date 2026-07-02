import { create } from "zustand";
import { persist } from "zustand/middleware";

const REQUIRED_CATEGORIES = ['case', 'case-fan', 'cooler', 'cpu', 'motherboard', 'ram', 'storage', 'psu', 'os'];

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

export const usePCStore = create(
  persist(
    (set, get) => ({
      selections: { os: DEFAULT_OS },
      adminMode: false,
      buildType: "full",
      onBuildComplete: null,

      mandatory: FULL_MANDATORY,

      setAdminMode: (mode) => set({ adminMode: mode }),

      setBuildType: (type) => {
        set({ buildType: type, mandatory: type === "parts" ? PARTS_MANDATORY : FULL_MANDATORY });
      },

      setComponent: (category, item) => {
        set((state) => {
          const newSelections = { ...state.selections, [category]: item };
          const gpuNeeded = (() => {
            const cpu = newSelections.cpu;
            const cpuHasIgpu = cpu && cpu.integrated_graphics && cpu.integrated_graphics.toLowerCase() !== "none";
            return !cpuHasIgpu;
          })();
          const allRequired = (gpuNeeded ? [...REQUIRED_CATEGORIES, 'gpu'] : REQUIRED_CATEGORIES).every(cat => newSelections[cat]);
          if (allRequired && state.onBuildComplete) {
            setTimeout(() => state.onBuildComplete(), 100);
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

      resetBuild: () => set({ selections: { os: DEFAULT_OS } }),

      getComponentsTotal: () => {
        const selections = get().selections;
        const buildType = get().buildType;
        return Object.entries(selections).reduce((sum, [cat, item]) => {
          if (cat === "os" && buildType === "full") return sum;
          const p = parseFloat(item?.price);
          return sum + (isNaN(p) ? 0 : p);
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
        for (const [cat, item] of Object.entries(selections)) {
          if (cat === "os" && buildType === "full") continue;
          items.push({ label: cat, name: item?.name || "—", price: parseFloat(item?.price) || 0 });
        }
        for (const s of Object.values(mandatory)) {
          items.push({ label: "service", name: s.name, price: parseFloat(s.price) || 0 });
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
        adminMode: state.adminMode
      })
    }
  )
);
