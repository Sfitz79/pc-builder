import { usePCStore } from "../store/usePCStore";
import { checkCompatibility } from "../utils/compatibility";
import { getGamingPerformanceSummary } from "../utils/performance";
import { Link, useNavigate } from "react-router-dom";
import { categories } from "../utils/categories";
import { BUILDER_CATEGORIES, SUBCATEGORY_GROUPS } from "../utils/builderConfig";
import { getBrand, getBrandLogo, getBrandFaviconUrl, getBrandPlaceholder, getItemImageUrl, getItemImageUrls } from "../utils/common";
import AIBuildVisual from "../components/AIBuildVisual";
import { PriceBreakdownPair } from "../components/PriceBreakdown";
import { useRef, useState, useEffect } from "react";
import html2canvas from "html2canvas";
import { jsPDF } from "jspdf";



const TOP_GAMES = [
  "Cyberpunk 2077", "Call of Duty: Warzone", "Fortnite", "Apex Legends", "Forza Horizon 5",
  "Black Myth: Wukong", "Elden Ring", "Grand Theft Auto VI", "Marvel Rivals", "Call of Duty: Black Ops 6"
];

function SummaryItem({ label, item }) {
  const logoUrl = item?.name ? getBrandLogo(item) : "";
  const faviconUrl = item?.name ? getBrandFaviconUrl(item) : "";
  const placeholderUrl = item?.name ? getBrandPlaceholder(item) : "";
  const productImages = item?.name ? getItemImageUrls(item) : [];
  const [imgIndex, setImgIndex] = useState(0);
  const productImageUrl = productImages[imgIndex] || null;
  const [showProductImg, setShowProductImg] = useState(!!productImageUrl);
  const [showBrandImg, setShowBrandImg] = useState(true);
  const [showFavicon, setShowFavicon] = useState(true);
  const itemName = item?.name || "";
  const displayBrand = itemName.split(" ")[0] || "";
  const modelName = itemName.substring(displayBrand.length).trim() || itemName;

  useEffect(() => {
    setImgIndex(0);
    setShowProductImg(!!(productImages[0]));
    setShowBrandImg(true);
    setShowFavicon(true);
  }, [item?.name]);

  const imgSrc = showProductImg && productImageUrl ? productImageUrl : showBrandImg && logoUrl ? logoUrl : showFavicon && faviconUrl ? faviconUrl : placeholderUrl;

  return (
    <div style={{ display: "flex", alignItems: "center", gap: "12px", padding: "10px 0", borderBottom: "1px solid rgba(255,255,255,0.04)" }}>
      <div style={{ width: "52px", height: "52px", borderRadius: "6px", background: "#1a1a2e", padding: "4px", flexShrink: 0, overflow: "hidden", display: "flex", alignItems: "center", justifyContent: "center" }}>
        {imgSrc ? (
          <img src={imgSrc} alt="" style={{ width: "100%", height: "100%", objectFit: "contain" }}
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
          />
        ) : (
          <span style={{ fontSize: "10px", opacity: 0.2 }}>N/A</span>
        )}
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: "13px", fontWeight: 600, color: "#e6e6e6" }}>{label}</div>
        <div style={{ fontSize: "12px", color: "#00eaff", display: "flex", alignItems: "center", flexWrap: "wrap", gap: "6px" }}>
          {item?.name ? (
            <>
              <span style={{
                display: "inline-block", padding: "1px 6px", borderRadius: "3px",
                background: "rgba(0,234,255,0.1)", color: "#00eaff",
                fontSize: "10px", fontWeight: 700
              }}>
                {displayBrand}
              </span>
              <span style={{ color: "#00eaff" }}>{modelName}</span>
            </>
          ) : (
            <span style={{ color: "#555" }}>Not selected</span>
          )}
        </div>
        {item?.name && (
          <div style={{ fontSize: "11px", color: "#555", marginTop: "2px" }}>
            {item.socket && <span>Socket: {item.socket} | </span>}
            {item.chipset && <span>Chipset: {item.chipset} | </span>}
            {item.speed && <span>Speed: {item.speed} | </span>}
            {item.memory && <span>VRAM: {item.memory}GB | </span>}
            {item.core_count && <span>{item.core_count}Cores | </span>}
            {item.wattage && <span>{item.wattage}W</span>}
            {item.rgb === "Yes" && <span style={{ color: "#00eaff" }}>RGB</span>}
          </div>
        )}
      </div>
    </div>
  );
}

export default function Summary() {
  const navigate = useNavigate();
  const selections = usePCStore(s => s.selections);
  const mandatory = usePCStore(s => s.mandatory);
  const buildType = usePCStore(s => s.buildType);
  const setBuildType = usePCStore(s => s.setBuildType);
  const getBundledPrice = usePCStore(s => s.getBundledPrice);
  const summaryRef = useRef(null);
  const [formStatus, setFormStatus] = useState("idle");

  const invoiceRef = useRef(null);
  const [invoiceStatus, setInvoiceStatus] = useState("idle");
  const [partsPdfStatus, setPartsPdfStatus] = useState("idle");

  const adminMode = usePCStore(s => s.adminMode);
  const issues = checkCompatibility(selections);
  const perf = getGamingPerformanceSummary(selections);
  const bundledPrice = getBundledPrice();
  const hasComponents = Object.keys(selections).length > 0;

  const REQUIRED = ["case", "case-fan", "cooler", "cpu", "motherboard", "ram", "storage", "psu", "os"];
  const cpu = selections.cpu;
  const gpuNeeded = !cpu || !cpu.integrated_graphics || cpu.integrated_graphics.toLowerCase() === "none";
  const allRequired = (gpuNeeded ? [...REQUIRED, "gpu"] : REQUIRED).every(cat => selections[cat]);

  useEffect(() => {
    if (!allRequired) {
      navigate("/builder", { replace: true });
    }
  }, [allRequired, navigate]);

  const partsOnlyPrice = (() => {
    const compTotal = Object.entries(selections).reduce((sum, [cat, item]) => {
      const p = parseFloat(item?.price);
      return sum + (isNaN(p) ? 0 : p);
    }, 0);
    return compTotal + 70;
  })();

  const getLabel = (catId) => {
    const c = BUILDER_CATEGORIES.find(cat => cat.id === catId) || categories.find(c => c.id === catId);
    return c?.label || catId;
  };

  const systemUse = [];
  if (perf.score >= 75) systemUse.push("4K Gaming");
  if (perf.score >= 50) systemUse.push("1440p Gaming");
  if (perf.score >= 30) systemUse.push("1080p Gaming");
  if (perf.score >= 60) systemUse.push("Content Creation");
  if (perf.score >= 40) systemUse.push("Streaming");
  systemUse.push("Everyday Productivity");

  const buildPartsText = BUILDER_CATEGORIES.map(cat => {
    const item = selections[cat.id];
    return item ? `${cat.label}: ${item.name} — £${parseFloat(item.price).toFixed(2)}` : null;
  }).filter(Boolean).concat(
    SUBCATEGORY_GROUPS.flatMap(g => g.categories).map(cat => {
      const item = selections[cat.id];
      return item ? `${cat.label}: ${item.name} — £${parseFloat(item.price).toFixed(2)}` : null;
    }).filter(Boolean)
  ).join("\n");
  const mandatoryText = Object.values(mandatory).map(m => `${m.name} — £${parseFloat(m.price).toFixed(2)}`).join("\n");
  const fullText = `PCTG PC Build\n\n--- Components ---\n${buildPartsText}\n\n--- Included ---\n${mandatoryText}\n\nTotal: £${bundledPrice.toLocaleString('en-GB')}`;

  const generateInvoice = async () => {
    setInvoiceStatus("generating");
    try {
      const canvas = await html2canvas(invoiceRef.current, { scale: 2, backgroundColor: "#ffffff" });
      const imgData = canvas.toDataURL("image/png");
      const pdf = new jsPDF("p", "mm", "a4");
      const pdfWidth = pdf.internal.pageSize.getWidth();
      const pdfHeight = (canvas.height * pdfWidth) / canvas.width;
      pdf.addImage(imgData, "PNG", 0, 0, pdfWidth, pdfHeight);
      pdf.save(`PCTG-Build-Invoice-${Date.now()}.pdf`);
      setInvoiceStatus("done");
      setTimeout(() => setInvoiceStatus("idle"), 3000);
    } catch {
      setInvoiceStatus("error");
      setTimeout(() => setInvoiceStatus("idle"), 4000);
    }
  };

  const partsPdfRef = useRef(null);

  const generatePartsPdf = async () => {
    setPartsPdfStatus("generating");
    try {
      const canvas = await html2canvas(partsPdfRef.current, { scale: 2, backgroundColor: "#ffffff" });
      const imgData = canvas.toDataURL("image/png");
      const pdf = new jsPDF("p", "mm", "a4");
      const pdfWidth = pdf.internal.pageSize.getWidth();
      const pdfHeight = (canvas.height * pdfWidth) / canvas.width;
      pdf.addImage(imgData, "PNG", 0, 0, pdfWidth, pdfHeight);
      pdf.save(`PCTG-Parts-URLs-${Date.now()}.pdf`);
      setPartsPdfStatus("done");
      setTimeout(() => setPartsPdfStatus("idle"), 3000);
    } catch {
      setPartsPdfStatus("error");
      setTimeout(() => setPartsPdfStatus("idle"), 4000);
    }
  };

  const shareLinks = {
    email: `mailto:?subject=PCTG PC Build Quote&body=${encodeURIComponent(fullText)}`,
    whatsapp: `https://wa.me/?text=${encodeURIComponent(fullText)}`,
    facebook: `https://www.facebook.com/sharer/sharer.php?quote=${encodeURIComponent(fullText)}&u=${encodeURIComponent(window.location.href)}`,
    sms: `sms:?body=${encodeURIComponent(fullText)}`,
  };

  return (
    <div style={{ maxWidth: "900px", margin: "0 auto" }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "20px", flexWrap: "wrap", gap: "10px" }}>
        <h1 style={{ fontSize: "22px", color: "#00eaff", textTransform: "uppercase", letterSpacing: "1px", textShadow: "0 0 10px rgba(0,234,255,0.3)" }}>
          Build Summary
        </h1>
        <div style={{ display: "flex", gap: "8px", alignItems: "center" }}>
          <div style={{ display: "flex", gap: "4px", background: "#0d0d18", borderRadius: "8px", padding: "3px", border: "1px solid rgba(0,234,255,0.1)" }}>
            <button onClick={() => setBuildType("full")}
              style={{
                padding: "6px 14px", borderRadius: "6px", cursor: "pointer", border: "none",
                background: buildType === "full" ? "rgba(0,234,255,0.12)" : "transparent",
                color: buildType === "full" ? "#00eaff" : "#666",
                fontFamily: "inherit", fontSize: "12px", fontWeight: 600
              }}>Full Build</button>
            <button onClick={() => setBuildType("parts")}
              style={{
                padding: "6px 14px", borderRadius: "6px", cursor: "pointer", border: "none",
                background: buildType === "parts" ? "rgba(0,234,255,0.12)" : "transparent",
                color: buildType === "parts" ? "#00eaff" : "#666",
                fontFamily: "inherit", fontSize: "12px", fontWeight: 600
              }}>Parts Only</button>
          </div>
          <Link to="/builder" className="button secondary small">← Back to Builder</Link>
        </div>
      </div>

      <div ref={summaryRef} style={{ background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.15)", padding: "24px", marginBottom: "20px" }}>
        {/* AI Visual Preview */}
        {hasComponents && (
          <div style={{ marginBottom: "24px" }}>
            <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "10px" }}>AI Build Preview</div>
            <AIBuildVisual selections={selections} />
          </div>
        )}

        {/* System Overview */}
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: "12px", marginBottom: "24px" }}>
          <div style={{ background: "rgba(0,234,255,0.04)", borderRadius: "8px", padding: "14px", border: "1px solid rgba(0,234,255,0.1)" }}>
            <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>Performance Tier</div>
            <div style={{ fontSize: "18px", fontWeight: 700, color: "#00eaff", marginTop: "4px" }}>{perf.gamingGrade}</div>
          </div>
          <div style={{ background: "rgba(0,234,255,0.04)", borderRadius: "8px", padding: "14px", border: "1px solid rgba(0,234,255,0.1)" }}>
            <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>Best Resolution</div>
            <div style={{ fontSize: "18px", fontWeight: 700, color: "#4ade80", marginTop: "4px" }}>{perf.optimalResolution} ★</div>
          </div>
          <div style={{ background: "rgba(0,234,255,0.04)", borderRadius: "8px", padding: "14px", border: "1px solid rgba(0,234,255,0.1)" }}>
            <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>Quality Settings</div>
            <div style={{ fontSize: "18px", fontWeight: 700, color: "#e6e6e6", marginTop: "4px" }}>{perf.qualityPreset}</div>
          </div>
          <div style={{ background: "rgba(0,234,255,0.04)", borderRadius: "8px", padding: "14px", border: "1px solid rgba(0,234,255,0.1)" }}>
            <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px" }}>Performance Score</div>
            <div style={{ fontSize: "18px", fontWeight: 700, color: "#ff005e", marginTop: "4px" }}>{perf.score}/100</div>
          </div>
        </div>

        {/* What it's good for */}
        <div style={{ marginBottom: "24px" }}>
          <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "10px" }}>Best For</div>
          <div style={{ display: "flex", flexWrap: "wrap", gap: "6px" }}>
            {systemUse.map(use => (
              <span key={use} style={{ padding: "4px 12px", borderRadius: "20px", background: "rgba(0,234,255,0.08)", border: "1px solid rgba(0,234,255,0.15)", color: "#00eaff", fontSize: "12px", fontWeight: 600 }}>
                {use}
              </span>
            ))}
          </div>
        </div>

        {/* Resolution capabilities */}
        <div style={{ marginBottom: "24px" }}>
          <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "10px" }}>Resolution Capabilities</div>
          <div style={{ display: "flex", gap: "8px", flexWrap: "wrap" }}>
            {perf.resolutionCapabilities?.map(res => (
              <div key={res} style={{
                padding: "10px 16px", borderRadius: "8px",
                background: res === perf.optimalResolution ? "rgba(74,222,128,0.1)" : "rgba(255,255,255,0.03)",
                border: `1px solid ${res === perf.optimalResolution ? "rgba(74,222,128,0.3)" : "rgba(255,255,255,0.06)"}`,
                textAlign: "center"
              }}>
                <div style={{ fontSize: "14px", fontWeight: 700, color: res === perf.optimalResolution ? "#4ade80" : "#888" }}>{res}</div>
                <div style={{ fontSize: "11px", color: res === perf.optimalResolution ? "#4ade80" : "#555", marginTop: "2px" }}>
                  {res === perf.optimalResolution ? "★ Optimal" : "Supported"}
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Performance Summary */}
        {perf.performanceSummary?.length > 0 && (
          <div style={{ marginBottom: "24px", padding: "16px", background: "linear-gradient(135deg, rgba(0,234,255,0.04), rgba(255,0,94,0.04))", borderRadius: "8px", border: "1px solid rgba(0,234,255,0.1)" }}>
            <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "8px" }}>What to Expect</div>
            <ul style={{ margin: 0, paddingLeft: "18px", lineHeight: "1.8", fontSize: "13px", color: "#ccc" }}>
              {perf.performanceSummary.map((s, i) => <li key={i}>{s}</li>)}
            </ul>
          </div>
        )}

        {/* FPS in Top 10 Games per resolution */}
        {perf.resolutionCapabilities?.map(res => {
          const games = res === perf.optimalResolution ? perf.gamePerformance : perf.allResPerformance?.[res];
          if (!games) return null;
          return (
            <div key={res} style={{ marginBottom: "24px" }}>
              <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "10px" }}>
                Expected FPS — Top 10 Games @ {res}
                {res === perf.optimalResolution && <span style={{ color: "#4ade80", marginLeft: "8px" }}>★ Optimal</span>}
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(250px, 1fr))", gap: "6px" }}>
                {games.map(game => (
                  <div key={game.name} style={{
                    display: "flex", justifyContent: "space-between", alignItems: "center",
                    padding: "8px 12px", borderRadius: "6px", background: "rgba(255,255,255,0.02)",
                    border: "1px solid rgba(255,255,255,0.04)"
                  }}>
                    <span style={{ fontSize: "12px", color: "#ccc", fontWeight: 500 }}>{game.name}</span>
                    <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
                      <span style={{ fontSize: "15px", fontWeight: 800, color: "#4ade80" }}>{game.boostedFps} <span style={{ fontSize: "11px", color: "#888" }}>FPS</span></span>
                      {game.hasFrameGen && <span style={{ fontSize: "9px", color: "#ff005e", background: "rgba(255,0,94,0.1)", padding: "1px 5px", borderRadius: "3px" }}>FG</span>}
                      {game.hasScaling && !game.hasFrameGen && <span style={{ fontSize: "9px", color: "#00eaff", background: "rgba(0,234,255,0.1)", padding: "1px 5px", borderRadius: "3px" }}>DLSS</span>}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          );
        })}

        {/* Selected Components */}
        <div style={{ marginBottom: "20px" }}>
          <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "10px" }}>Selected Components</div>
          {BUILDER_CATEGORIES.map(cat => {
            const isOsFree = cat.id === "os" && buildType === "full";
            const item = isOsFree ? { ...selections[cat.id], price: "0.00", _displayNote: "Included in build fee" } : selections[cat.id];
            return <SummaryItem key={cat.id} label={cat.label} item={item} />;
          })}
          {SUBCATEGORY_GROUPS.flatMap(g => g.categories).map(cat => {
            const item = selections[cat.id];
            if (!item) return null;
            return <SummaryItem key={cat.id} label={cat.label} item={item} />;
          })}
        </div>

        {/* Included Services */}
        <div style={{ marginBottom: "20px" }}>
          <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "8px" }}>Included With Every Build</div>
          {Object.values(mandatory).map(m => (
            <div key={m.name} style={{ padding: "6px 0", fontSize: "13px", color: "#aaa", borderBottom: "1px solid rgba(255,255,255,0.03)" }}>
              {m.name}
            </div>
          ))}
        </div>

        {/* Pricing */}
        <PriceBreakdownPair selections={selections} adminMode={adminMode} />

        {/* Compatibility */}
        {issues.length > 0 && (
          <div style={{ marginTop: "16px", padding: "12px 16px", background: "rgba(245,158,11,0.08)", borderRadius: "8px", border: "1px solid rgba(245,158,11,0.2)" }}>
            <div style={{ fontSize: "12px", fontWeight: 700, color: "#f59e0b", marginBottom: "6px" }}>⚠ Compatibility Warnings</div>
            <ul style={{ margin: 0, paddingLeft: "16px", fontSize: "12px", color: "#f59e0b", lineHeight: "1.6" }}>
              {issues.map((msg, i) => <li key={i}>{msg}</li>)}
            </ul>
          </div>
        )}
      </div>

      {/* Actions */}
      <div style={{ display: "flex", gap: "10px", flexWrap: "wrap" }}>
        <Link to="/builder" className="button secondary">← Back to Builder</Link>
        <button className="button" onClick={() => window.print()}>🖨 Print</button>
      </div>

      {/* Hidden invoice for PDF capture */}
      <div ref={invoiceRef} style={{ position: "absolute", left: "-9999px", top: 0, width: "800px", padding: "40px", fontFamily: "Arial, sans-serif", background: "#fff", color: "#000" }}>
        <div style={{ textAlign: "center", marginBottom: "30px", borderBottom: "2px solid #00eaff", paddingBottom: "15px" }}>
          <h1 style={{ fontSize: "28px", color: "#111", margin: "0 0 5px" }}>PCTG PC Build</h1>
          <p style={{ color: "#555", fontSize: "14px", margin: 0 }}>Invoice — {new Date().toLocaleDateString('en-GB')}</p>
        </div>
        <table style={{ width: "100%", borderCollapse: "collapse", marginBottom: "20px", fontSize: "13px" }}>
          <thead>
            <tr style={{ borderBottom: "2px solid #333" }}>
              <th style={{ textAlign: "left", padding: "8px 6px", color: "#333" }}>Component</th>
              <th style={{ textAlign: "left", padding: "8px 6px", color: "#333" }}>Product</th>
              <th style={{ textAlign: "right", padding: "8px 6px", color: "#333" }}>Price</th>
            </tr>
          </thead>
          <tbody>
            {BUILDER_CATEGORIES.map(cat => {
              const item = selections[cat.id];
              const isOsFree = cat.id === "os" && buildType === "full";
              const osPrice = isOsFree ? 0 : parseFloat(item?.price || 0);
              return (
                <tr key={cat.id} style={{ borderBottom: "1px solid #ddd" }}>
                  <td style={{ padding: "8px 6px", color: "#444" }}>{cat.label}</td>
                  <td style={{ padding: "8px 6px", color: "#111", fontWeight: 600 }}>
                    {item?.name || "—"}
                    {isOsFree && <span style={{ color: "#888", fontWeight: 400, fontSize: "11px" }}> (included)</span>}
                  </td>
                  <td style={{ padding: "8px 6px", textAlign: "right", color: "#111" }}>{item ? `£${osPrice.toFixed(2)}` : "—"}</td>
                </tr>
              );
            })}
            {SUBCATEGORY_GROUPS.flatMap(g => g.categories).map(cat => {
              const item = selections[cat.id];
              if (!item) return null;
              return (
                <tr key={cat.id} style={{ borderBottom: "1px solid #ddd" }}>
                  <td style={{ padding: "8px 6px", color: "#444" }}>{cat.label}</td>
                  <td style={{ padding: "8px 6px", color: "#111", fontWeight: 600 }}>{item.name}</td>
                  <td style={{ padding: "8px 6px", textAlign: "right", color: "#111" }}>£{parseFloat(item.price).toFixed(2)}</td>
                </tr>
              );
            })}
            {Object.values(mandatory).map(m => (
              <tr key={m.name} style={{ borderBottom: "1px solid #ddd" }}>
                <td style={{ padding: "8px 6px", color: "#666", fontStyle: "italic" }}>Service</td>
                <td style={{ padding: "8px 6px", color: "#111", fontWeight: 600 }}>{m.name}</td>
                <td style={{ padding: "8px 6px", textAlign: "right", color: "#111" }}>£{parseFloat(m.price).toFixed(2)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ textAlign: "right", fontSize: "20px", fontWeight: 800, color: "#00eaff", padding: "10px 6px" }}>
          Total: £{bundledPrice.toLocaleString('en-GB')}
        </div>
        <div style={{ marginTop: "30px", fontSize: "11px", color: "#888", textAlign: "center", borderTop: "1px solid #ddd", paddingTop: "15px" }}>
          PCTG • Build Service, Testing & Royal Mail Delivery Included • 2 Year Warranty
        </div>
      </div>

      {/* Hidden parts PDF for capture */}
      <div ref={partsPdfRef} style={{ position: "absolute", left: "-9999px", top: 0, width: "800px", padding: "40px", fontFamily: "Arial, sans-serif", background: "#fff", color: "#000" }}>
        <div style={{ textAlign: "center", marginBottom: "30px", borderBottom: "2px solid #ff005e", paddingBottom: "15px" }}>
          <h1 style={{ fontSize: "28px", color: "#111", margin: "0 0 5px" }}>PCTG Parts List</h1>
          <p style={{ color: "#555", fontSize: "14px", margin: 0 }}>System Design — {new Date().toLocaleDateString('en-GB')}</p>
        </div>
        <table style={{ width: "100%", borderCollapse: "collapse", marginBottom: "20px", fontSize: "13px" }}>
          <thead>
            <tr style={{ borderBottom: "2px solid #333" }}>
              <th style={{ textAlign: "left", padding: "8px 6px", color: "#333" }}>Component</th>
              <th style={{ textAlign: "left", padding: "8px 6px", color: "#333" }}>Product</th>
              <th style={{ textAlign: "left", padding: "8px 6px", color: "#333" }}>URL</th>
              <th style={{ textAlign: "right", padding: "8px 6px", color: "#333" }}>Price</th>
            </tr>
          </thead>
          <tbody>
            {BUILDER_CATEGORIES.map(cat => {
              const item = selections[cat.id];
              if (!item) return null;
              const imageUrl = (item.image || "").split(",")[0];
              return (
                <tr key={cat.id} style={{ borderBottom: "1px solid #ddd" }}>
                  <td style={{ padding: "8px 6px", color: "#444" }}>{cat.label}</td>
                  <td style={{ padding: "8px 6px", color: "#111", fontWeight: 600 }}>{item.name}</td>
                  <td style={{ padding: "8px 6px", color: "#0066cc", fontSize: "11px", wordBreak: "break-all" }}>{imageUrl || "—"}</td>
                  <td style={{ padding: "8px 6px", textAlign: "right", color: "#111" }}>£{parseFloat(item.price).toFixed(2)}</td>
                </tr>
              );
            })}
            {SUBCATEGORY_GROUPS.flatMap(g => g.categories).map(cat => {
              const item = selections[cat.id];
              if (!item) return null;
              const imageUrl = (item.image || "").split(",")[0];
              return (
                <tr key={cat.id} style={{ borderBottom: "1px solid #ddd" }}>
                  <td style={{ padding: "8px 6px", color: "#444" }}>{cat.label}</td>
                  <td style={{ padding: "8px 6px", color: "#111", fontWeight: 600 }}>{item.name}</td>
                  <td style={{ padding: "8px 6px", color: "#0066cc", fontSize: "11px", wordBreak: "break-all" }}>{imageUrl || "—"}</td>
                  <td style={{ padding: "8px 6px", textAlign: "right", color: "#111" }}>£{parseFloat(item.price).toFixed(2)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
        <div style={{ marginTop: "20px", fontSize: "11px", color: "#888", textAlign: "center", borderTop: "1px solid #ddd", paddingTop: "15px" }}>
          PCTG System Design • Purchase parts at the links above
        </div>
      </div>

      {/* Purchase Section */}
      {hasComponents && adminMode && (
        <div style={{ marginTop: "28px", background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(255,0,94,0.2)", padding: "24px" }}>
          <div style={{ fontSize: "12px", color: "#ff005e", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "16px", fontWeight: 700 }}>
            Purchase This Build — {buildType === "full" ? "Full Build" : "Parts Only"}
          </div>

          {buildType === "full" ? (
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: "12px", marginBottom: "20px" }}>
              <button onClick={generateInvoice} disabled={invoiceStatus !== "idle"}
                style={{
                  padding: "14px", borderRadius: "8px", cursor: "pointer", border: "1px solid rgba(0,234,255,0.2)",
                  background: "rgba(0,234,255,0.06)", color: invoiceStatus === "error" ? "#ef4444" : "#00eaff",
                  fontFamily: "inherit", fontSize: "13px", fontWeight: 600, transition: "all 0.2s"
                }}>
                {invoiceStatus === "generating" ? "Generating PDF..." : invoiceStatus === "done" ? "✓ PDF Downloaded" : invoiceStatus === "error" ? "✕ Failed — Try Again" : "📄 Download Invoice (PDF)"}
              </button>

              <a href="https://www.paypal.com/checkoutnow" target="_blank" rel="noopener noreferrer"
                style={{
                  padding: "14px", borderRadius: "8px", display: "flex", alignItems: "center", justifyContent: "center",
                  background: "linear-gradient(135deg, #0070ba, #003087)", color: "#fff", textDecoration: "none",
                  fontFamily: "inherit", fontSize: "13px", fontWeight: 600, border: "none", cursor: "pointer"
                }}>
                ₿ Checkout with PayPal
              </a>
            </div>
          ) : (
            <div style={{ marginBottom: "20px" }}>
              <div style={{ background: "rgba(0,234,255,0.04)", borderRadius: "8px", padding: "14px", border: "1px solid rgba(0,234,255,0.1)", marginBottom: "12px" }}>
                <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "0.5px", marginBottom: "4px" }}>Parts Only — System Design Charge</div>
                <div style={{ fontSize: "24px", fontWeight: 800, color: "#00eaff" }}>£35.00</div>
                <div style={{ fontSize: "11px", color: "#555", marginTop: "4px" }}>One-time fee for custom parts list & design. Once paid, you'll receive component URLs as a PDF.</div>
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: "12px" }}>
                <button onClick={generateInvoice} disabled={invoiceStatus !== "idle"}
                  style={{
                    padding: "14px", borderRadius: "8px", cursor: "pointer", border: "1px solid rgba(0,234,255,0.2)",
                    background: "rgba(0,234,255,0.06)", color: invoiceStatus === "error" ? "#ef4444" : "#00eaff",
                    fontFamily: "inherit", fontSize: "13px", fontWeight: 600, transition: "all 0.2s"
                  }}>
                  {invoiceStatus === "generating" ? "Generating PDF..." : invoiceStatus === "done" ? "✓ PDF Downloaded" : invoiceStatus === "error" ? "✕ Failed — Try Again" : "📄 Download Invoice (PDF)"}
                </button>

                <a href={`https://www.paypal.com/checkoutnow`} target="_blank" rel="noopener noreferrer"
                  style={{
                    padding: "14px", borderRadius: "8px", display: "flex", alignItems: "center", justifyContent: "center",
                    background: "linear-gradient(135deg, #0070ba, #003087)", color: "#fff", textDecoration: "none",
                    fontFamily: "inherit", fontSize: "13px", fontWeight: 600, border: "none", cursor: "pointer"
                  }}>
                  ₿ Pay £35 System Design Fee
                </a>

                <button onClick={generatePartsPdf} disabled={partsPdfStatus !== "idle"}
                  style={{
                    padding: "14px", borderRadius: "8px", cursor: "pointer", border: "1px solid rgba(255,0,94,0.2)",
                    background: "rgba(255,0,94,0.06)", color: partsPdfStatus === "error" ? "#ef4444" : "#ff005e",
                    fontFamily: "inherit", fontSize: "13px", fontWeight: 600, transition: "all 0.2s"
                  }}>
                  {partsPdfStatus === "generating" ? "Generating PDF..." : partsPdfStatus === "done" ? "✓ Parts URLs PDF Sent" : partsPdfStatus === "error" ? "✕ Failed — Try Again" : "📋 Send Parts URLs as PDF"}
                </button>
              </div>
            </div>
          )}

          <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "10px" }}>
            Share this build
          </div>
          <div style={{ display: "flex", gap: "8px", flexWrap: "wrap" }}>
            {[
              { href: shareLinks.email, label: "Email", color: "#888" },
              { href: shareLinks.whatsapp, label: "WhatsApp", color: "#25D366" },
              { href: shareLinks.facebook, label: "Facebook", color: "#1877F2" },
              { href: shareLinks.sms, label: "SMS", color: "#888" },
            ].map(s => (
              <a key={s.label} href={s.href} target="_blank" rel="noopener noreferrer"
                style={{
                  padding: "8px 16px", borderRadius: "6px", cursor: "pointer", textDecoration: "none",
                  background: "rgba(255,255,255,0.04)", border: "1px solid rgba(255,255,255,0.1)",
                  color: s.color, fontFamily: "inherit", fontSize: "12px", fontWeight: 600
                }}>
                {s.label}
              </a>
            ))}
          </div>
          <div style={{ marginTop: "10px", fontSize: "11px", color: "#444" }}>
            {buildType === "full"
              ? `Total: £${bundledPrice.toLocaleString('en-GB')} incl. build, testing, delivery & warranty`
              : `Parts total: £${bundledPrice.toLocaleString('en-GB')} + £35 System Design fee`}
          </div>
        </div>
      )}
      {!adminMode && hasComponents && (
        <div style={{ marginTop: "28px", background: "#0d0d18", borderRadius: "12px", border: "1px solid rgba(255,255,255,0.06)", padding: "24px", textAlign: "center" }}>
          <div style={{ fontSize: "13px", color: "#555" }}>
            😎 Purchase options are hidden. <span style={{ color: "#00eaff", cursor: "pointer" }}
              onClick={() => { const user = prompt("Enter admin username:"); if (user) { const pw = prompt("Enter admin password:"); if (pw) { const au = import.meta.env.VITE_ADMIN_USER || "admin"; usePCStore.getState().setAdminMode(user === au && pw === (import.meta.env.VITE_ADMIN_PASSWORD || "admin")); } } }}
            >Unlock</span> to view pricing and purchase options.
          </div>
        </div>
      )}
    </div>
  );
}
