import { useEffect, useMemo, useState, useRef } from "react";
import { loadCSV } from "../utils/loadCSV";
import { isOptionCompatible } from "../utils/compatibility";
import { inferCpuSocket as commonInferCpuSocket, normalizeToken, inferChipset, isRGB, inferCoolerType, getBrand, getBrandLogo, getBrandFaviconUrl, getBrandPlaceholder, getItemImageUrls, isModernComponent } from "../utils/common.js";

function Thumbnail({ item }) {
  const logoUrl = getBrandLogo(item);
  const faviconUrl = getBrandFaviconUrl(item);
  const placeholderUrl = getBrandPlaceholder(item);
  const productImages = getItemImageUrls(item);
  const [imgIndex, setImgIndex] = useState(0);
  const productImageUrl = productImages[imgIndex] || null;
  const [showProductImg, setShowProductImg] = useState(!!productImageUrl);
  const [showBrandImg, setShowBrandImg] = useState(true);
  const [showFavicon, setShowFavicon] = useState(true);

  useEffect(() => {
    setImgIndex(0);
    setShowProductImg(!!(productImages[0]));
    setShowBrandImg(true);
    setShowFavicon(true);
  }, [item.image]);

  const imgSrc = showProductImg && productImageUrl ? productImageUrl : showBrandImg && logoUrl ? logoUrl : showFavicon && faviconUrl ? faviconUrl : placeholderUrl;

  return (
    <div className="item-thumbnail-mini-wrap" style={{ position: 'relative', width: '100px', height: '100px', flexShrink: 0 }}>
      <img
        src={imgSrc}
        alt=""
        className="item-thumbnail-mini"
        loading="lazy"
        onError={() => {
          if (showProductImg && productImageUrl) {
            if (imgIndex < productImages.length - 1) {
              setImgIndex(imgIndex + 1);
            } else {
              setShowProductImg(false);
            }
          } else if (showBrandImg) {
            setShowBrandImg(false);
          } else if (showFavicon) {
            setShowFavicon(false);
          }
        }}
        style={{ width: '100%', height: '100%', objectFit: 'contain', padding: '4px' }}
      />
    </div>
  );
}

export default function DropdownSelector({
  category,
  selections,
  selectedItem,
  onSelect
}) {
  const [items, setItems] = useState([]);
  const [activeFilters, setActiveFilters] = useState({});
  const [filterText, setFilterText] = useState("");
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  const dropdownRef = useRef(null);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setIsDropdownOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  useEffect(() => {
    loadCSV(category.file).then(loadedItems => {
      const enhanced = loadedItems.map(item => {
        const newItem = { ...item };
        if (category.id === "cpu" && !newItem.socket) {
          const s = commonInferCpuSocket(newItem);
          if (s) newItem.socket = s;
        }
        if (category.id === "motherboard" && !newItem.chipset) {
          const c = inferChipset(newItem.name);
          if (c) newItem.chipset = c;
        }
        if (category.id === "cooler" && !newItem.type) {
          newItem.type = inferCoolerType(newItem);
        }
        if (category.id === "ram") {
          if (newItem.speed) {
            const speedStr = String(newItem.speed).replace(/"/g, "");
            if (speedStr.includes(",")) {
              const parts = speedStr.split(",");
              if (parts.length >= 2) {
                newItem.ram_type = "DDR" + parts[0].trim();
                newItem.speed = parts[1].trim();
              } else {
                newItem.speed = speedStr.replace(/,/g, "");
              }
            } else {
              newItem.speed = speedStr.replace(/,/g, "");
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
        if (isRGB(newItem)) {
          newItem.rgb = "Yes";
        }
        return newItem;
      });
      setItems(enhanced);
    });
    setActiveFilters({});
    setFilterText("");
  }, [category.file, category.id]);

  const compatibleItems = useMemo(() => {
    return items.filter((item) => {
      if (!isModernComponent(category.id, item)) return false;
      return isOptionCompatible(category.id, item, selections);
    });
  }, [items, category.id, selections.cpu, selections.motherboard, selections.ram, selections.gpu, selections.psu, selections.case]);

  const sortedItems = useMemo(
    () =>
      [...compatibleItems].sort((a, b) => {
        const aName = a.name || "";
        const bName = b.name || "";
        return aName.localeCompare(bName, undefined, {
          numeric: true,
          sensitivity: "base"
        });
      }),
    [compatibleItems]
  );

  const filterOptions = useMemo(() => {
    if (sortedItems.length === 0) return {};
    
    // Ignore columns that aren't useful for categorical filtering
    const ignore = [ "price", "image", "Image" ];
    const headers = Object.keys(sortedItems[0] || {}).filter(h => !ignore.includes(h));
    
    // Ensure critical headers are present even if not in first item
    if (category.id === "cpu" && !headers.includes("socket")) headers.push("socket");
    if (category.id === "motherboard" && !headers.includes("chipset")) headers.push("chipset");
    if (category.id === "cooler" && !headers.includes("type")) headers.push("type");
    
    const options = {};
    headers.forEach(h => {
        const uniqueValues = Array.from(new Set(sortedItems.map(item => String(item[h] ?? "")))).filter(v => v !== "" && v !== "null" && v !== "undefined" && v !== "UNKNOWN").sort();
        if (uniqueValues.length > 0) {
            options[h] = uniqueValues;
        }
    });
    return options;
  }, [sortedItems]);

  const filteredItems = useMemo(() => {
    return sortedItems.filter((item) => {
      // Text search
      const normalizedText = filterText.trim().toLowerCase();
      if (normalizedText) {
        const name = String(item?.name ?? "").toLowerCase();
        const brand = getBrand(item).toLowerCase();
        const model = getModelPart(item).toLowerCase();
        if (!(name.includes(normalizedText) || brand.includes(normalizedText) || model.includes(normalizedText))) {
            return false;
        }
      }

      // Header filters
      for (const [header, selectedSet] of Object.entries(activeFilters)) {
        if (selectedSet && selectedSet.size > 0) {
          const val = String(item[header] ?? "");
          if (!selectedSet.has(val)) {
            return false;
          }
        }
      }
      return true;
    });
  }, [sortedItems, filterText, activeFilters]);

  const toggleFilter = (header, value) => {
    setActiveFilters(prev => {
      const next = { ...prev };
      const currentSet = next[header] ? new Set(next[header]) : new Set();
      if (currentSet.has(value)) {
        currentSet.delete(value);
      } else {
        currentSet.add(value);
      }
      
      if (currentSet.size === 0) {
        delete next[header];
      } else {
        next[header] = currentSet;
      }
      return next;
    });
  };

  const clearHeaderFilter = (header) => {
    setActiveFilters(prev => {
      const next = { ...prev };
      delete next[header];
      return next;
    });
  };

  const cpuItemsBySocket = useMemo(() => {
    if (category.id !== "cpu") {
      return [];
    }

    const groups = filteredItems.reduce((acc, item) => {
      const socket = commonInferCpuSocket(item) || "UNKNOWN";
      if (!acc[socket]) {
        acc[socket] = [];
      }
      acc[socket].push(item);
      return acc;
    }, {});

    return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b));
  }, [category.id, filteredItems]);

  useEffect(() => {
    if (!selectedItem) {
      return;
    }

    const stillCompatible = compatibleItems.some(
      (item) => item.name === selectedItem.name
    );
    if (!stillCompatible) {
      onSelect(null);
    }
  }, [selectedItem, compatibleItems, onSelect]);


  return (
    <div className="selector">
      <label className="selector-label">
        <img
          className="selector-category-icon"
          src={getCategoryIcon(category)}
          alt={`${category.label} icon`}
          loading="lazy"
        />
        <span>{category.label}</span>
      </label>
      <div className="selector-search-container" style={{ display: 'flex', gap: '8px' }}>
        <input
          className="selector-filter"
          type="text"
          placeholder={`Search ${category.label} name/model...`}
          value={filterText}
          onChange={(e) => setFilterText(e.target.value)}
          
          style={{ flex: 1 }}
        />
        
      </div>

      <div className="selector-filter-row">
        {Object.entries(filterOptions).map(([header, values]) => (
          <FilterClickBox
            key={header}
            header={header}
            values={values}
            selectedValues={activeFilters[header] || new Set()}
            onToggle={(val) => toggleFilter(header, val)}
            onClear={() => clearHeaderFilter(header)}
          />
        ))}
      </div>

      <div className="custom-dropdown" ref={dropdownRef}>
        <div
          className="custom-dropdown-toggle"
          onClick={() => setIsDropdownOpen(!isDropdownOpen)}
        >
          <span>
            {selectedItem ? formatItemLabel(selectedItem) : `Select ${category.label}`}
          </span>
          <span>{isDropdownOpen ? "▲" : "▼"}</span>
        </div>

        {isDropdownOpen && (
          <div className="custom-dropdown-menu">
            <div
              className="custom-dropdown-item"
              onClick={() => {
                onSelect(null);
                setIsDropdownOpen(false);
              }}
            >
              None / Clear Selection
            </div>

            {category.id === "cpu"
              ? cpuItemsBySocket.map(([socket, socketItems]) => (
                  <div key={socket}>
                    <div className="custom-dropdown-group-header">
                      Socket {socket}
                    </div>
                    {socketItems.map((item, i) => (
                      <div
                        key={`${socket}-${i}`}
                        className={`custom-dropdown-item ${
                          selectedItem?.name === item.name ? "selected" : ""
                        }`}
                        onClick={() => {
                          onSelect(item);
                          setIsDropdownOpen(false);
                        }}
                      >
                        <Thumbnail item={item} categoryLabel={category.label} />
                        <div className="item-info">
                          {formatItemLabel(item)}
                        </div>
                      </div>
                    ))}
                  </div>
                ))
              : filteredItems.map((item, i) => (
                  <div
                    key={i}
                    className={`custom-dropdown-item ${
                      selectedItem?.name === item.name ? "selected" : ""
                    }`}
                    onClick={() => {
                      onSelect(item);
                      setIsDropdownOpen(false);
                    }}
                  >
                    <Thumbnail item={item} categoryLabel={category.label} />
                    <div className="item-info">{formatItemLabel(item)}</div>
                  </div>
                ))}
          </div>
        )}
      </div>

      <small>
        Showing {filteredItems.length} of {items.length} options (Excludes Discontinued)
      </small>
    </div>
  );
}

function FilterClickBox({ header, values, selectedValues, onToggle, onClear }) {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef(null);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const label = header.replace(/_/g, " ").replace(/\b\w/g, c => c.toUpperCase());

  return (
    <div className="filter-dropdown" ref={containerRef}>
      <button
        type="button"
        className={`filter-button ${selectedValues.size > 0 ? "active" : ""}`}
        onClick={() => setIsOpen(!isOpen)}
      >
        <span>{label} {selectedValues.size > 0 ? `(${selectedValues.size})` : ""}</span>
        <span style={{ fontSize: "10px", opacity: 0.7 }}>▼</span>
      </button>

      {isOpen && (
        <div className="filter-panel">
          {values.map(val => (
            <label key={val} className="filter-option">
              <input
                type="checkbox"
                checked={selectedValues.has(val)}
                onChange={() => onToggle(val)}
              />
              <span>{val}</span>
            </label>
          ))}
          {selectedValues.size > 0 && (
            <button type="button" className="filter-clear" onClick={onClear}>
              Clear
            </button>
          )}
        </div>
      )}
    </div>
  );
}

function formatItemLabel(item) {
  const brand = getBrand(item);
  const model = getModelPart(item);
  
  const ignore = ["name", "price", "brand", "model", "part_number", "partNumber", "sku", "manufacturer", "image"];
  const details = Object.entries(item)
    .filter(([key, val]) => !ignore.includes(key) && val !== "" && val !== null && val !== undefined)
    .map(([key, val]) => {
      let label = key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
      let value = val;
      
      if (key === "rgb" && val === "Yes") return "RGB";
      
      // Specialist formatting for some fields
      if (key === "speed" && !String(val).includes("MHz") && !String(val).includes("MT")) {
        const num = Number(val);
        if (num >= 4000) value = `DDR5 ${val} MT/s`;
        else if (num >= 2000) value = `DDR4 ${val} MT/s`;
        else value = `DDR${val}`;
      } else if (key === "ram_type" && String(val).includes("DDR")) {
        return `RAM: ${val}`;
      } else if (key === "modules" && String(val).includes(",")) {
        const parts = String(val).split(",");
        if (parts.length === 2) {
          value = `${parts[0]}x${parts[1]}GB`;
        }
      } else if (key === "capacity" && String(val).includes("GB")) {
        return `Capacity: ${val}`;
      } else if (key === "vram" || key === "memory") {
        value = `${val}GB`;
      } else if (key.includes("clock") || key === "refresh_rate") {
        value = `${val}MHz`;
      }
      
      return `${label}: ${value}`;
    })
    .join(" | ");

  return `${brand} | ${model}${details ? " | " + details : ""}`;
}


function getModelPart(item) {
  const explicitModel = normalizeToken(item?.model || item?.part_number || item?.partNumber || item?.sku);
  if (explicitModel) {
    return explicitModel;
  }

  const name = String(item?.name ?? "").trim();
  if (!name) {
    return "N/A";
  }

  const parts = name.split(" ");
  return parts.length > 1 ? parts.slice(1).join(" ") : name;
}








function getCategoryIcon(category) {
  const key = String(category?.id ?? "").toLowerCase();

  const iconKeyByCategory = {
    cpu: "cpu",
    gpu: "gpu",
    motherboard: "board",
    ram: "ram",
    storage: "storage",
    psu: "psu",
    case: "case",
    cooler: "cooler",
    speakers: "audio",
    webcam: "camera",
    "wired-network-card": "network",
    "wireless-network-card": "network",
    "thermal-paste": "paste",
    ups: "power",
    "case-fan": "fan",
    os: "os",
    "case-accessory": "tool",
    "fan-controller": "control",
    mouse: "mouse",
    keyboard: "keyboard",
    monitor: "monitor",
    headphones: "headphones",
    "external-hard-drive": "storage",
    "optical-drive": "disc"
  };

  const iconName = iconKeyByCategory[key] || "chip";
  return neonIconDataUri(iconName);
}

function neonIconDataUri(iconName) {
  const paths = {
    cpu: '<rect x="7" y="7" width="34" height="34" rx="6"/><rect x="16" y="16" width="16" height="16" rx="3"/><path d="M24 2v5M24 41v5M2 24h5M41 24h5M10 2v4M38 2v4M10 42v4M38 42v4"/>',
    gpu: '<rect x="4" y="12" width="40" height="24" rx="5"/><circle cx="18" cy="24" r="6"/><path d="M30 20h10M30 24h10M30 28h10"/>',
    board: '<rect x="5" y="5" width="38" height="38" rx="5"/><rect x="11" y="11" width="14" height="14" rx="2"/><path d="M29 12h8M29 18h8M11 31h26M11 36h18"/>',
    ram: '<rect x="4" y="16" width="40" height="14" rx="3"/><path d="M10 16v14M16 16v14M22 16v14M28 16v14M34 16v14M8 34h32"/>',
    storage: '<rect x="8" y="6" width="30" height="36" rx="4"/><circle cx="23" cy="30" r="4"/><path d="M16 14h14"/>',
    psu: '<rect x="6" y="8" width="36" height="32" rx="4"/><circle cx="18" cy="24" r="8"/><path d="M30 16h8M30 22h8M30 28h8"/>',
    case: '<rect x="12" y="4" width="24" height="40" rx="4"/><circle cx="24" cy="14" r="2"/><path d="M18 22h12M18 28h12M18 34h12"/>',
    cooler: '<circle cx="24" cy="24" r="14"/><circle cx="24" cy="24" r="4"/><path d="M24 10v6M24 32v6M10 24h6M32 24h6M14 14l4 4M30 30l4 4M14 34l4-4M30 18l4-4"/>',
    audio: '<path d="M8 30h8l10 8V10L16 18H8z"/><path d="M32 18c3 2 4 10 0 12M36 14c5 4 6 16 0 20"/>',
    camera: '<rect x="6" y="12" width="36" height="24" rx="5"/><circle cx="24" cy="24" r="7"/><path d="M14 12l4-4h12l4 4"/>',
    network: '<circle cx="24" cy="28" r="2"/><path d="M10 30a14 14 0 0 1 28 0M14 24a10 10 0 0 1 20 0M18 18a6 6 0 0 1 12 0"/>',
    paste: '<path d="M10 34l14-14 10 10-14 14z"/><path d="M28 16l6-6M16 28l-6 6"/>',
    power: '<rect x="8" y="8" width="32" height="32" rx="4"/><path d="M24 12v14M18 22h12M14 32h20"/>',
    fan: '<circle cx="24" cy="24" r="4"/><path d="M24 8c6 0 8 6 4 10M40 24c0 6-6 8-10 4M24 40c-6 0-8-6-4-10M8 24c0-6 6-8 10-4"/><circle cx="24" cy="24" r="14"/>',
    os: '<rect x="6" y="8" width="36" height="30" rx="4"/><path d="M6 16h36M18 8v30"/>',
    tool: '<path d="M10 36l10-10M24 22l12-12M30 10l8 8M8 38l8-8"/>',
    control: '<rect x="8" y="10" width="32" height="28" rx="4"/><circle cx="18" cy="24" r="4"/><circle cx="30" cy="24" r="4"/>',
    mouse: '<rect x="14" y="6" width="20" height="36" rx="10"/><path d="M24 10v8"/>',
    keyboard: '<rect x="4" y="12" width="40" height="24" rx="4"/><path d="M10 20h4M16 20h4M22 20h4M28 20h4M34 20h4M10 28h28"/>',
    monitor: '<rect x="6" y="8" width="36" height="24" rx="3"/><path d="M18 40h12M24 32v8"/>',
    headphones: '<path d="M12 26v-2a12 12 0 0 1 24 0v2"/><rect x="9" y="24" width="6" height="12" rx="2"/><rect x="33" y="24" width="6" height="12" rx="2"/>',
    disc: '<circle cx="24" cy="24" r="14"/><circle cx="24" cy="24" r="4"/>',
    chip: '<rect x="8" y="8" width="32" height="32" rx="4"/><rect x="16" y="16" width="16" height="16" rx="2"/>'
  };

  const path = paths[iconName] || paths.chip;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">
  <defs>
    <filter id="g" x="-50%" y="-50%" width="200%" height="200%">
      <feGaussianBlur stdDeviation="1.2" result="b"/>
      <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
    </filter>
  </defs>
  <rect width="48" height="48" rx="10" fill="#0d0d18" stroke="#ff00cc" stroke-width="1.2"/>
  <g fill="none" stroke="#00eaff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" filter="url(#g)">
    ${path}
  </g>
</svg>`;

  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

