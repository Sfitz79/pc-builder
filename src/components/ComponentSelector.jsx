import { useEffect, useMemo, useState, useRef } from "react";
import { loadCSV } from "../utils/loadCSV";
import { isOptionCompatible } from "../utils/compatibility";
import { inferCpuSocket, inferChipset, inferCoolerType, isRGB, getBrand, getBrandLogo, getBrandFaviconUrl, getBrandPlaceholder, getItemImageUrl, getItemImageUrls, isModernComponent, toNumber } from "../utils/common.js";
import { isMultiSelect } from "../utils/builderConfig";
import { usePCStore } from "../store/usePCStore";

export default function ComponentSelector({ category, selections, selectedItem, onSelect, onClose, showPrices = false }) {
  const [items, setItems] = useState([]);
  const [filterText, setFilterText] = useState("");
  const [activeFilters, setActiveFilters] = useState({});
  const [rangeFilters, setRangeFilters] = useState({});
  const [sortBy, setSortBy] = useState("price-asc");
  const [showAvailableOnly, setShowAvailableOnly] = useState(true);
  const panelRef = useRef(null);

  const multiMode = isMultiSelect(category.id);
  const isMultiSelected = usePCStore(s => s.isMultiSelected);
  const toggleMultiComponent = usePCStore(s => s.toggleMultiComponent);

  useEffect(() => {
    function handleClick(e) {
      if (panelRef.current && !panelRef.current.contains(e.target)) onClose();
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, [onClose]);

  useEffect(() => {
    loadCSV(category.file).then(loadedItems => {
      const enhanced = loadedItems.map(item => {
        const newItem = { ...item };
        if (category.id === "cpu" && !newItem.socket) {
          const s = inferCpuSocket(newItem);
          if (s) newItem.socket = s;
        }
        if (category.id === "motherboard" && !newItem.chipset) {
          const c = inferChipset(newItem.name);
          if (c) newItem.chipset = c;
        }
        if (category.id === "cooler" && !newItem.type) {
          newItem.type = inferCoolerType(newItem);
        }
        if (category.id === "ram" && newItem.speed) {
          const speedStr = String(newItem.speed).replace(/"/g, "");
          if (speedStr.includes(",")) {
            const parts = speedStr.split(",");
            if (parts.length >= 2) {
              newItem.ram_type = "DDR" + parts[0].trim();
              newItem.speed = parts[1].trim();
            }
          }
          if (newItem.modules) {
            const modStr = String(newItem.modules).replace(/"/g, "");
            if (modStr.includes(",")) {
              const parts = modStr.split(",");
              if (parts.length >= 2) {
                newItem.modules = parts[0].trim();
                newItem.capacity = parts[1].trim() + "GB";
              }
            }
          }
        }
        if (isRGB(newItem)) newItem.rgb = "Yes";
        return newItem;
      });
      setItems(enhanced);
    });
    setFilterText("");
    setActiveFilters({});
    setRangeFilters({});
    setSortBy("price-asc");
  }, [category.file, category.id]);

  const compatibleItems = useMemo(() => {
    return items.filter(item => {
      if (!isModernComponent(category.id, item)) return false;
      return isOptionCompatible(category.id, item, selections);
    });
  }, [items, category.id, selections]);

  const sortedItems = useMemo(() =>
    [...compatibleItems].sort((a, b) => {
      if (sortBy === "price-asc") return (toNumber(a.price) || Infinity) - (toNumber(b.price) || Infinity);
      if (sortBy === "price-desc") return (toNumber(b.price) || 0) - (toNumber(a.price) || 0);
      if (sortBy === "name-desc") {
        return (b.name || "").localeCompare(a.name || "", undefined, { numeric: true, sensitivity: "base" });
      }
      if (sortBy === "cores") return (toNumber(b.core_count) || 0) - (toNumber(a.core_count) || 0);
      if (sortBy === "speed") return (toNumber(b.speed) || 0) - (toNumber(a.speed) || 0);
      if (sortBy === "wattage") return (toNumber(b.wattage) || 0) - (toNumber(a.wattage) || 0);
      if (sortBy === "memory") return (toNumber(b.memory) || 0) - (toNumber(a.memory) || 0);
      return (a.name || "").localeCompare(b.name || "", undefined, { numeric: true, sensitivity: "base" });
    }),
    [compatibleItems, sortBy]
  );

  const numericFields = useMemo(() => {
    if (sortedItems.length === 0) return {};
    const numKeyWords = ["core_count", "core_clock", "boost_clock", "tdp", "wattage", "memory", "speed", "screen_size", "refresh_rate", "response_time", "capacity", "fan_rpm"];
    const fields = {};
    for (const key of numKeyWords) {
      const vals = sortedItems.map(i => toNumber(i[key])).filter(v => v > 0);
      if (vals.length > 1) {
        fields[key] = { min: Math.min(...vals), max: Math.max(...vals) };
      }
    }
    return fields;
  }, [sortedItems]);

  const filterOptions = useMemo(() => {
    if (sortedItems.length === 0) return {};
    const ignore = new Set([
      "price", "image", "Image", "name", "rating",
      "core_count", "core_clock", "boost_clock", "tdp", "wattage", "memory",
      "screen_size", "refresh_rate", "response_time", "fan_rpm", "noise_level",
      "speed", "capacity",
    ]);
    const headers = Object.keys(sortedItems[0] || {}).filter(h => !ignore.has(h));
    if (category.id === "cpu" && !headers.includes("socket")) headers.push("socket");
    if (category.id === "motherboard" && !headers.includes("chipset")) headers.push("chipset");
    if (category.id === "cooler" && !headers.includes("type")) headers.push("type");

    const options = {};
    headers.forEach(h => {
      const vals = Array.from(new Set(sortedItems.map(item => String(item[h] ?? "")))).filter(v => v && v !== "null" && v !== "undefined" && v !== "UNKNOWN").sort();
      if (vals.length > 1 && vals.length < 60) options[h] = vals;
    });
    return options;
  }, [sortedItems, category.id]);

  const filteredItems = useMemo(() => {
    return sortedItems.filter(item => {
      const text = filterText.trim().toLowerCase();
      if (text) {
        const name = String(item?.name ?? "").toLowerCase();
        const brand = getBrand(item).toLowerCase();
        if (!name.includes(text) && !brand.includes(text)) return false;
      }
      for (const [header, selectedSet] of Object.entries(activeFilters)) {
        if (selectedSet && selectedSet.size > 0) {
          const val = String(item[header] ?? "");
          if (!selectedSet.has(val)) return false;
        }
      }
      for (const [field, range] of Object.entries(rangeFilters)) {
        const val = toNumber(item[field]);
        if (val > 0 && (val < range.min || val > range.max)) return false;
      }
      if (showAvailableOnly) {
        const price = toNumber(item.price);
        if (price <= 0) return false;
      }
      return true;
    });
  }, [sortedItems, filterText, activeFilters, rangeFilters, showAvailableOnly]);

  const cpuItemsBySocket = useMemo(() => {
    if (category.id !== "cpu") return [];
    const groups = filteredItems.reduce((acc, item) => {
      const socket = inferCpuSocket(item) || "UNKNOWN";
      if (!acc[socket]) acc[socket] = [];
      acc[socket].push(item);
      return acc;
    }, {});
    return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b));
  }, [category.id, filteredItems]);

  const toggleFilter = (header, value) => {
    setActiveFilters(prev => {
      const next = { ...prev };
      const currentSet = next[header] ? new Set(next[header]) : new Set();
      if (currentSet.has(value)) currentSet.delete(value);
      else currentSet.add(value);
      if (currentSet.size === 0) delete next[header];
      else next[header] = currentSet;
      return next;
    });
  };

  const setRangeFilter = (field, min, max) => {
    setRangeFilters(prev => ({ ...prev, [field]: { min, max } }));
  };

  const clearAllFilters = () => {
    setFilterText("");
    setActiveFilters({});
    setRangeFilters({});
  };

  const hasActiveFilters = filterText || Object.keys(activeFilters).length > 0 || Object.keys(rangeFilters).length > 0;

  const handleSelect = (item) => {
    if (multiMode) {
      toggleMultiComponent(category.id, item);
    } else {
      onSelect(item);
      onClose();
    }
  };

  return (
    <div style={{
      position: "fixed", inset: 0, zIndex: 3000,
      background: "rgba(0,0,0,0.7)", display: "flex",
      alignItems: "center", justifyContent: "center",
      backdropFilter: "blur(4px)"
    }}>
      <div ref={panelRef} style={{
        background: "#0d0d18", border: "1px solid rgba(0,234,255,0.3)",
        borderRadius: "12px", maxWidth: "960px", width: "95%",
        maxHeight: "90vh", display: "flex", flexDirection: "column",
        boxShadow: "0 20px 60px rgba(0,0,0,0.8)"
      }}>
        <div style={{ padding: "14px 20px", borderBottom: "1px solid rgba(0,234,255,0.1)", display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <h3 style={{ margin: 0, color: "#00eaff", fontSize: "16px" }}>
            Choose {category.label}
            {multiMode && <span style={{ fontSize: "11px", color: "#888", marginLeft: "8px", fontWeight: 400 }}>(select multiple)</span>}
          </h3>
          <button onClick={onClose} style={{ background: "none", border: "none", color: "#666", cursor: "pointer", fontSize: "20px", padding: "4px 8px" }}>✕</button>
        </div>

        <div style={{ padding: "10px 20px", borderBottom: "1px solid rgba(255,255,255,0.05)", display: "flex", gap: "8px", alignItems: "center", flexWrap: "wrap" }}>
          <div style={{ position: "relative", flex: 1, minWidth: "160px" }}>
            <span style={{ position: "absolute", left: "10px", top: "50%", transform: "translateY(-50%)", color: "#555", fontSize: "13px" }}>🔍</span>
            <input
              type="text"
              placeholder={`Search ${category.label}...`}
              value={filterText}
              onChange={e => setFilterText(e.target.value)}
              style={{ width: "100%", boxSizing: "border-box", background: "#1a1a2e", color: "#e6e6e6", border: "1px solid #333", borderRadius: "6px", padding: "8px 12px 8px 32px", fontSize: "13px", outline: "none" }}
            />
          </div>
          <select
            value={sortBy}
            onChange={e => setSortBy(e.target.value)}
            style={{ padding: "8px 10px", fontSize: "12px", background: "#1a1a2e", color: "#e6e6e6", border: "1px solid #333", borderRadius: "6px", outline: "none", cursor: "pointer" }}
          >
            <option value="price-asc">Price: Low → High</option>
            <option value="price-desc">Price: High → Low</option>
            <option value="name">Name A-Z</option>
            <option value="name-desc">Name Z-A</option>
            <option value="cores">Cores (high first)</option>
            <option value="speed">Speed (high first)</option>
            <option value="wattage">Wattage (high first)</option>
            <option value="memory">VRAM (high first)</option>
          </select>
          <label style={{ display: "flex", alignItems: "center", gap: "6px", fontSize: "11px", color: "#888", cursor: "pointer", whiteSpace: "nowrap" }}>
            <input type="checkbox" checked={showAvailableOnly} onChange={e => setShowAvailableOnly(e.target.checked)}
              style={{ accentColor: "#00eaff", width: "14px", height: "14px", cursor: "pointer" }} />
            Available only
          </label>
          {hasActiveFilters && (
            <button onClick={clearAllFilters} style={{ padding: "6px 12px", fontSize: "11px", background: "none", border: "1px solid #e74c3c", color: "#e74c3c", borderRadius: "4px", cursor: "pointer", whiteSpace: "nowrap" }}>
              Clear Filters
            </button>
          )}
        </div>

        <div style={{ padding: "24px 20px", display: "flex", flexWrap: "wrap", gap: "6px", borderBottom: "1px solid rgba(255,255,255,0.05)" }}>
          {Object.entries(numericFields).map(([field, range]) => (
            <RangeSlider
              key={field}
              label={field.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase())}
              min={range.min}
              max={range.max}
              value={rangeFilters[field] || range}
              onChange={(min, max) => setRangeFilter(field, min, max)}
            />
          ))}
          {Object.entries(filterOptions).map(([header, values]) => (
            <FilterDropdown
              key={header}
              label={header.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase())}
              values={values}
              selectedValues={activeFilters[header] || new Set()}
              onToggle={val => toggleFilter(header, val)}
            />
          ))}
        </div>

        <div style={{ flex: 1, overflowY: "auto", padding: "4px 0" }}>
          {category.id === "cpu" ? (
            cpuItemsBySocket.map(([socket, socketItems]) => (
              <div key={socket}>
                <div style={{ padding: "5px 20px", fontSize: "10px", color: "#ff005e", fontWeight: 700, textTransform: "uppercase", letterSpacing: "0.5px", background: "rgba(255,0,94,0.05)", position: "sticky", top: 0, zIndex: 2 }}>
                  Socket {socket} — {socketItems.length} CPU{socketItems.length !== 1 ? "s" : ""}
                </div>
                {socketItems.map((item, i) => (
                  <ItemRow key={i} item={item} category={category} multiMode={multiMode} isMultiSelected={isMultiSelected} onSelect={handleSelect} onClose={onClose} showPrices={showPrices} />
                ))}
              </div>
            ))
          ) : (
            filteredItems.map((item, i) => (
              <ItemRow key={i} item={item} category={category} multiMode={multiMode} isMultiSelected={isMultiSelected} onSelect={handleSelect} onClose={onClose} showPrices={showPrices} />
            ))
          )}
          {filteredItems.length === 0 && (
            <div style={{ padding: "40px", textAlign: "center", color: "#666", fontSize: "13px" }}>
              {items.length === 0 ? "Loading components..." : "No compatible items found. Try adjusting filters."}
            </div>
          )}
        </div>

        <div style={{ padding: "8px 20px", borderTop: "1px solid rgba(255,255,255,0.05)", fontSize: "11px", color: "#555", display: "flex", justifyContent: "space-between" }}>
          <span>Showing {filteredItems.length} of {items.length} options</span>
          {hasActiveFilters && <span style={{ color: "#00eaff" }}>Filters active</span>}
        </div>
      </div>
    </div>
  );
}

function ItemRow({ item, category, multiMode, isMultiSelected, onSelect, onClose, showPrices }) {
  const brand = getBrand(item);
  const itemName = item.name || "";
  const displayBrand = itemName.split(" ")[0] || brand;
  const modelName = itemName.substring(displayBrand.length).trim() || itemName;
  const logoUrl = getBrandLogo(item);
  const faviconUrl = getBrandFaviconUrl(item);
  const placeholderUrl = getBrandPlaceholder(item);
  const [imgIndex, setImgIndex] = useState(0);
  const productImages = getItemImageUrls(item);
  const productImageUrl = productImages[imgIndex] || null;
  const [showProductImg, setShowProductImg] = useState(!!productImageUrl);
  const [showBrandImg, setShowBrandImg] = useState(true);
  const [showFavicon, setShowFavicon] = useState(true);
  const imgSrc = showProductImg && productImageUrl ? productImageUrl : showBrandImg && logoUrl ? logoUrl : showFavicon && faviconUrl ? faviconUrl : placeholderUrl;

  const selected = multiMode ? isMultiSelected(category.id, item.name) : false;

  const specItems = [];

  if (category.id === "ram") {
    const speed = item.speed && String(item.speed).length > 2
      ? String(item.speed) : item.modules ? String(item.modules) : "";
    if (speed) specItems.push({ label: "Speed", value: speed + "MHz" });
    if (item.ram_type) {
      specItems.push({ label: "Type", value: item.ram_type });
    } else if (item.speed && /^\d{1,2}$/.test(String(item.speed))) {
      specItems.push({ label: "Type", value: "DDR" + item.speed });
    }
    if (item.capacity) {
      specItems.push({ label: "Capacity", value: item.capacity });
    }
    const casMatch = String(item.name).match(/C(\d{2})(?:[^\d]|$)/);
    if (casMatch) specItems.push({ label: "CAS", value: "C" + casMatch[1] });
  } else {
    if (item.socket) specItems.push({ label: "Socket", value: item.socket });
    if (item.chipset) specItems.push({ label: "Chipset", value: item.chipset });
    if (item.core_count) specItems.push({ label: "Cores", value: item.core_count });
    if (item.core_clock) specItems.push({ label: "Base Clock", value: item.core_clock + " GHz" });
    if (item.boost_clock) specItems.push({ label: "Boost Clock", value: item.boost_clock + " GHz" });
    if (item.speed) specItems.push({ label: "Speed", value: item.speed });
    if (item.memory) specItems.push({ label: "VRAM", value: item.memory + "GB" });
    if (item.capacity) specItems.push({ label: "Capacity", value: item.capacity });
    if (item.wattage) specItems.push({ label: "Wattage", value: item.wattage + "W" });
    if (item.tdp) specItems.push({ label: "TDP", value: item.tdp + "W" });
    if (item.type) specItems.push({ label: "Type", value: item.type });
    if (item.modules) specItems.push({ label: "Modules", value: item.modules });
    if (item.form_factor) specItems.push({ label: "Form Factor", value: item.form_factor });
    if (item.rgb === "Yes") specItems.push({ label: "RGB", value: "Yes" });
    if (item.screen_size) specItems.push({ label: "Screen", value: item.screen_size });
    if (item.resolution) specItems.push({ label: "Resolution", value: item.resolution });
    if (item.refresh_rate) specItems.push({ label: "Refresh", value: item.refresh_rate });
    if (item.panel_type) specItems.push({ label: "Panel", value: item.panel_type });
    if (item.interface) specItems.push({ label: "Interface", value: item.interface });
  }

  const price = toNumber(item.price);

  return (
    <div
      onClick={() => onSelect(item)}
      style={{
        display: "flex", alignItems: "center", gap: "14px", padding: "8px 20px",
        cursor: "pointer", transition: "all 0.15s",
        background: selected ? "rgba(0,234,255,0.12)" : "transparent",
        borderBottom: "1px solid rgba(255,255,255,0.03)"
      }}
      onMouseEnter={e => { if (!selected) e.currentTarget.style.background = "rgba(0,234,255,0.02)"; }}
      onMouseLeave={e => { if (!selected) e.currentTarget.style.background = "transparent"; }}
    >
      {multiMode && (
        <div style={{ width: 20, minWidth: 20, display: "flex", alignItems: "center", justifyContent: "center" }}>
          <input
            type="checkbox"
            checked={selected}
            readOnly
            style={{ accentColor: "#00eaff", width: "16px", height: "16px", cursor: "pointer" }}
          />
        </div>
      )}
      <div style={{ width: 80, height: 80, minWidth: 80, minHeight: 80, borderRadius: 8, background: '#1a1a2e', flexShrink: 0, overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center', border: selected ? '2px solid #00eaff' : '2px solid transparent' }}>
        <img
          src={imgSrc}
          alt=""
          style={{ width: 80, height: 80, objectFit: 'contain' }}
          onError={e => {
            if (showProductImg && productImageUrl) {
              if (imgIndex < productImages.length - 1) setImgIndex(imgIndex + 1);
              else setShowProductImg(false);
            } else if (showBrandImg) setShowBrandImg(false);
            else if (showFavicon) setShowFavicon(false);
          }}
        />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: "13px", fontWeight: 600, color: "#e6e6e6", display: "flex", alignItems: "center", flexWrap: "wrap", gap: "6px" }}>
          <span style={{
            display: "inline-block", padding: "2px 8px", borderRadius: "4px",
            background: "rgba(0,234,255,0.12)", color: "#00eaff",
            fontSize: "11px", fontWeight: 700, letterSpacing: "0.3px"
          }}>
            {displayBrand}
          </span>
          <span style={{ color: "#e6e6e6" }}>{' '}{modelName}</span>
        </div>
        <div style={{ display: "flex", flexWrap: "wrap", gap: "2px 12px", marginTop: "4px" }}>
          {specItems.slice(0, 6).map((s, i) => (
            <span key={i} style={{ fontSize: "11px", color: "#666" }}>
              <span style={{ color: "#555" }}>{s.label}:</span> {s.value}
            </span>
          ))}
        </div>
      </div>
      <div style={{ flexShrink: 0, textAlign: "right" }}>
        {price > 0 && (
          <div style={{ fontSize: "14px", fontWeight: 700, color: "#00eaff", marginBottom: "4px" }}>
            £{price.toLocaleString("en-GB", { minimumFractionDigits: 2 })}
          </div>
        )}
        <button className="choose-btn" style={{ padding: "7px 14px", background: selected ? "rgba(0,234,255,0.2)" : "linear-gradient(135deg, #00eaff, #ff005e)", color: "#fff", border: "none", borderRadius: "6px", fontSize: "12px", fontWeight: 600, cursor: "pointer", whiteSpace: "nowrap" }}>
          {selected ? (multiMode ? "✓ Selected" : "Selected") : (multiMode ? "+ Add" : "+ Add")}
        </button>
      </div>
    </div>
  );
}

function FilterDropdown({ label, values, selectedValues, onToggle }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const ref = useRef(null);

  useEffect(() => {
    function handleClick(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, []);

  const filtered = search
    ? values.filter(v => v.toLowerCase().includes(search.toLowerCase()))
    : values;

  return (
    <div ref={ref} style={{ position: "relative" }}>
      <button onClick={() => setOpen(!open)} style={{
        padding: "4px 10px", fontSize: "11px", background: selectedValues.size > 0 ? "rgba(0,234,255,0.15)" : "#1a1a2e",
        border: `1px solid ${selectedValues.size > 0 ? "#00eaff" : "#333"}`, borderRadius: "4px",
        color: selectedValues.size > 0 ? "#00eaff" : "#888", cursor: "pointer", whiteSpace: "nowrap"
      }}>
        {label} {selectedValues.size > 0 ? `(${selectedValues.size})` : ""} ▾
      </button>
      {open && (
        <div style={{
          position: "absolute", top: "100%", left: 0, zIndex: 100, marginTop: "4px",
          background: "#1a1a2e", border: "1px solid rgba(0,234,255,0.3)", borderRadius: "8px",
          padding: "8px", minWidth: "200px", maxHeight: "320px", overflowY: "auto",
          boxShadow: "0 12px 40px rgba(0,0,0,0.6)"
        }}>
          <input
            type="text"
            placeholder={`Filter ${label}...`}
            value={search}
            onChange={e => setSearch(e.target.value)}
            style={{ width: "100%", boxSizing: "border-box", padding: "4px 8px", fontSize: "11px", background: "#0d0d18", color: "#e6e6e6", border: "1px solid #333", borderRadius: "4px", marginBottom: "6px", outline: "none" }}
          />
          {filtered.map(v => (
            <label key={v} style={{ display: "flex", alignItems: "center", gap: "8px", padding: "5px 8px", cursor: "pointer", fontSize: "12px", color: "#ccc", borderRadius: "4px", transition: "background 0.15s" }}
              onMouseEnter={e => e.currentTarget.style.background = "rgba(0,234,255,0.08)"}
              onMouseLeave={e => e.currentTarget.style.background = "transparent"}>
              <input type="checkbox" checked={selectedValues.has(v)} onChange={() => onToggle(v)}
                style={{ accentColor: "#00eaff", width: "14px", height: "14px" }} />
              {v}
            </label>
          ))}
          {selectedValues.size > 0 && (
            <button onClick={() => { Array.from(selectedValues).forEach(k => onToggle(k)); }}
              style={{ marginTop: "6px", padding: "4px 10px", fontSize: "10px", background: "none", border: "1px solid #e74c3c", color: "#e74c3c", borderRadius: "4px", cursor: "pointer", width: "100%" }}>
              Clear all
            </button>
          )}
        </div>
      )}
    </div>
  );
}

function RangeSlider({ label, min, max, value, onChange }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const [localMin, setLocalMin] = useState(value?.min ?? min);
  const [localMax, setLocalMax] = useState(value?.max ?? max);

  useEffect(() => {
    setLocalMin(value?.min ?? min);
    setLocalMax(value?.max ?? max);
  }, [value, min, max]);

  useEffect(() => {
    function handleClick(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener("mousedown", handleClick);
    return () => document.removeEventListener("mousedown", handleClick);
  }, []);

  const isActive = localMin !== min || localMax !== max;

  return (
    <div ref={ref} style={{ position: "relative" }}>
      <button onClick={() => setOpen(!open)} style={{
        padding: "4px 10px", fontSize: "11px", background: isActive ? "rgba(0,234,255,0.15)" : "#1a1a2e",
        border: `1px solid ${isActive ? "#00eaff" : "#333"}`, borderRadius: "4px",
        color: isActive ? "#00eaff" : "#888", cursor: "pointer", whiteSpace: "nowrap"
      }}>
        {label} {isActive ? `(${localMin}-${localMax})` : ""} ▾
      </button>
      {open && (
        <div style={{
          position: "absolute", top: "100%", left: 0, zIndex: 100, marginTop: "4px",
          background: "#1a1a2e", border: "1px solid rgba(0,234,255,0.3)", borderRadius: "8px",
          padding: "14px", minWidth: "240px", boxShadow: "0 12px 40px rgba(0,0,0,0.6)"
        }}>
          <div style={{ display: "flex", justifyContent: "space-between", marginBottom: "8px" }}>
            <input type="number" value={localMin} min={min} max={max}
              onChange={e => { setLocalMin(Math.max(min, Math.min(max, Number(e.target.value)))); }}
              style={{ width: "70px", padding: "4px 6px", fontSize: "12px", background: "#1a1a2e", color: "#e6e6e6", border: "1px solid #333", borderRadius: "4px", textAlign: "center" }}
            />
            <span style={{ color: "#555", fontSize: "12px", alignSelf: "center" }}>to</span>
            <input type="number" value={localMax} min={min} max={max}
              onChange={e => { setLocalMax(Math.max(min, Math.min(max, Number(e.target.value)))); }}
              style={{ width: "70px", padding: "4px 6px", fontSize: "12px", background: "#1a1a2e", color: "#e6e6e6", border: "1px solid #333", borderRadius: "4px", textAlign: "center" }}
            />
          </div>
          <input type="range" min={min} max={max} value={localMin}
            onChange={e => { setLocalMin(Math.min(Number(e.target.value), localMax)); }}
            style={{ width: "100%", accentColor: "#00eaff" }}
          />
          <input type="range" min={min} max={max} value={localMax}
            onChange={e => { setLocalMax(Math.max(Number(e.target.value), localMin)); }}
            style={{ width: "100%", accentColor: "#00eaff", marginTop: "2px" }}
          />
          <div style={{ display: "flex", justifyContent: "space-between", marginTop: "8px" }}>
            <button onClick={() => { setLocalMin(min); setLocalMax(max); onChange(min, max); setOpen(false); }}
              style={{ padding: "4px 10px", fontSize: "10px", background: "none", border: "1px solid #555", color: "#888", borderRadius: "3px", cursor: "pointer" }}>
              Reset
            </button>
            <button onClick={() => { onChange(localMin, localMax); setOpen(false); }}
              style={{ padding: "4px 14px", fontSize: "10px", background: "linear-gradient(135deg, #00eaff, #ff005e)", border: "none", color: "#fff", borderRadius: "3px", cursor: "pointer", fontWeight: 600 }}>
              Apply
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
