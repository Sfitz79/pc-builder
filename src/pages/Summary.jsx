import { usePCStore } from "../store/usePCStore";
import { checkCompatibility } from "../utils/compatibility";
import { getGamingPerformanceSummary } from "../utils/performance";
import { Link, useNavigate } from "react-router-dom";
import { BUILDER_CATEGORIES, SUBCATEGORY_GROUPS, ADDON_GROUPS } from "../utils/builderConfig";
import { getBrandLogo, getBrandFaviconUrl, getBrandPlaceholder, getItemImageUrls } from "../utils/common";
import AIBuildVisual from "../components/AIBuildVisual";
import { PriceBreakdownPair } from "../components/PriceBreakdown";
import { useRef, useState, useEffect } from "react";
import html2canvas from "html2canvas";
import { jsPDF } from "jspdf";
import PayPalCheckout from "../components/PayPalCheckout";



function SummaryItem({ label, item, qty }) {
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
  const displayQty = qty || item?.qty || 1;

  return (
    <div style={{ display: "flex", alignItems: "center", gap: "12px", padding: "10px 0", borderBottom: "1px solid rgba(255,255,255,0.04)" }}>
      <div style={{ width: "52px", height: "52px", borderRadius: "6px", background: "#1a1a2e", padding: "4px", flexShrink: 0, overflow: "hidden", display: "flex", alignItems: "center", justifyContent: "center" }}>
        {imgSrc ? (
          <img src={imgSrc} alt="" style={{ width: "100%", height: "100%", objectFit: "contain" }}
            onError={() => {
              if (showProductImg && productImageUrl) {
                if (imgIndex < productImages.length - 1) setImgIndex(imgIndex + 1);
                else setShowProductImg(false);
              } else if (showBrandImg) setShowBrandImg(false);
              else if (showFavicon) setShowFavicon(false);
            }}
          />
        ) : (
          <span style={{ fontSize: "10px", opacity: 0.2 }}>N/A</span>
        )}
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: "13px", fontWeight: 600, color: "#e6e6e6" }}>
          {label}
          {displayQty > 1 && <span style={{ fontSize: "11px", color: "#00eaff", marginLeft: "6px" }}>×{displayQty}</span>}
        </div>
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
              {' '}<span style={{ color: "#00eaff" }}>{modelName}</span>
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

  const systemUse = [];
  if (perf.score >= 75) systemUse.push("4K Gaming");
  if (perf.score >= 50) systemUse.push("1440p Gaming");
  if (perf.score >= 30) systemUse.push("1080p Gaming");
  if (perf.score >= 60) systemUse.push("Content Creation");
  if (perf.score >= 40) systemUse.push("Streaming");
  systemUse.push("Everyday Productivity");

  const buildPartsText = (() => {
    const lines = [];
    const addEntries = (cats) => {
      for (const cat of cats) {
        const val = selections[cat.id];
        if (!val) continue;
        if (Array.isArray(val)) {
          val.forEach(item => {
            const qty = item.qty || 1;
            const p = parseFloat(item.price) || 0;
            lines.push(`${cat.label}: ${item.name}${qty > 1 ? ` ×${qty}` : ""} — £${(p * qty).toFixed(2)}`);
          });
        } else {
          const p = parseFloat(val.price) || 0;
          lines.push(`${cat.label}: ${val.name} — £${p.toFixed(2)}`);
        }
      }
    };
    addEntries(BUILDER_CATEGORIES);
    addEntries(SUBCATEGORY_GROUPS.flatMap(g => g.categories));
    addEntries(ADDON_GROUPS.flatMap(g => g.categories));
    return lines.join("\n");
  })();
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

        {/* Plain English Summary */}
        <div style={{ marginBottom: "24px", padding: "20px", background: "linear-gradient(135deg, rgba(0,234,255,0.05), rgba(74,222,128,0.05))", borderRadius: "12px", border: "1px solid rgba(0,234,255,0.15)" }}>
          <div style={{ fontSize: "11px", color: "#555", textTransform: "uppercase", letterSpacing: "1px", marginBottom: "10px" }}>Why This PC Is Perfect For You</div>
          <div style={{ fontSize: "14px", color: "#ccc", lineHeight: "1.7", marginBottom: "10px" }}>
            {perf.gamingGrade === "Elite Gaming" || perf.gamingGrade === "Extreme Gaming" ? (
              <>This isn't just a PC — it's a powerhouse that will handle anything you throw at it for years to come. Whether you're diving into the latest blockbuster games at max settings, streaming your gameplay in crystal clarity, or editing videos, this machine won't break a sweat. It's built with premium components that work together seamlessly, giving you buttery-smooth gameplay, lightning-fast load times, and the kind of performance that makes every experience feel effortless. No lag, no stuttering — just pure, uninterrupted enjoyment.</>
            ) : perf.gamingGrade === "High-End Gaming" ? (
              <>This PC strikes the perfect balance between power and value. You'll be able to play all the latest games on high settings with smooth, responsive frame rates — think immersive open worlds, fast-paced shooters, and stunning racing games, all looking their best. It handles everyday tasks instantly, loads games in seconds, and is built to stay relevant for years. Whether you're gaming, working, or streaming, this machine gives you a premium experience without the top-tier price tag.</>
            ) : (
              <>This PC is built to deliver a fantastic gaming experience without breaking the bank. You'll enjoy smooth gameplay at solid settings in all your favourite titles, with fast load times and reliable performance day in and day out. It handles everything from schoolwork and browsing to casual content creation with ease. Think of it as your all-rounder — capable, dependable, and ready to play the latest games while leaving room to upgrade down the line.</>
            )}
          </div>
          <div style={{ fontSize: "13px", color: "#00eaff", fontWeight: 600 }}>
            {perf.score >= 70 ? "Top-tier components mean top-tier experiences. You deserve this." : "Smart performance where it counts — built for real people, not just specs."}
          </div>
        </div>

        {/* High-Converting Pitch */}
        <div style={{ marginBottom: "24px", padding: "24px", background: "linear-gradient(135deg, rgba(255,0,94,0.06), rgba(0,234,255,0.06))", borderRadius: "12px", border: "1px solid rgba(255,0,94,0.2)", textAlign: "center" }}>
          <div style={{ fontSize: "18px", fontWeight: 800, color: "#fff", marginBottom: "8px", letterSpacing: "0.5px" }}>
            Ready to Experience This Machine?
          </div>
          <div style={{ fontSize: "13px", color: "#aaa", lineHeight: "1.7", maxWidth: "600px", margin: "0 auto 16px" }}>
            You've just designed a custom PC built around <strong style={{ color: "#00eaff" }}>your</strong> needs. Every component has been hand-picked, compatibility-checked, and optimised for real-world performance. We don't just ship parts — we build, test, and deliver a fully assembled system with a <strong style={{ color: "#4ade80" }}>2-year warranty</strong>, so you can unbox and play from day one.
          </div>
          <div style={{ display: "flex", flexWrap: "wrap", justifyContent: "center", gap: "8px", marginBottom: "16px" }}>
            <span style={{ padding: "4px 12px", borderRadius: "20px", background: "rgba(0,234,255,0.08)", border: "1px solid rgba(0,234,255,0.15)", color: "#00eaff", fontSize: "11px", fontWeight: 600 }}>✅ Professionally Built</span>
            <span style={{ padding: "4px 12px", borderRadius: "20px", background: "rgba(0,234,255,0.08)", border: "1px solid rgba(0,234,255,0.15)", color: "#00eaff", fontSize: "11px", fontWeight: 600 }}>✅ Fully Tested</span>
            <span style={{ padding: "4px 12px", borderRadius: "20px", background: "rgba(0,234,255,0.08)", border: "1px solid rgba(0,234,255,0.15)", color: "#00eaff", fontSize: "11px", fontWeight: 600 }}>✅ 2-Year Warranty</span>
            <span style={{ padding: "4px 12px", borderRadius: "20px", background: "rgba(0,234,255,0.08)", border: "1px solid rgba(0,234,255,0.15)", color: "#00eaff", fontSize: "11px", fontWeight: 600 }}>✅ Free Delivery</span>
          </div>
          <div style={{ fontSize: "12px", color: "#555", marginBottom: "4px" }}>
            Total price from <span style={{ fontSize: "22px", fontWeight: 800, color: "#fff" }}>£{bundledPrice.toLocaleString('en-GB')}</span>
          </div>
          <div style={{ fontSize: "11px", color: "#555", marginBottom: "16px" }}>
            Build, testing, delivery & warranty — all included
          </div>
          {adminMode ? (
            <div style={{ display: "flex", gap: "10px", justifyContent: "center", flexWrap: "wrap" }}>
              <button className="button" onClick={() => window.print()}
                style={{ padding: "12px 28px", fontSize: "14px", fontWeight: 700, cursor: "pointer", borderRadius: "8px", border: "none", background: "linear-gradient(135deg, #00eaff, #0099cc)", color: "#000" }}>
                🚀 Secure Your Build Now
              </button>
            </div>
          ) : (
            <div style={{ fontSize: "12px", color: "#555" }}>
              <span style={{ color: "#00eaff", cursor: "pointer" }}
                onClick={() => { const user = prompt("Enter admin username:"); if (user) { const pw = prompt("Enter admin password:"); if (pw) { const au = import.meta.env.VITE_ADMIN_USER || "admin"; usePCStore.getState().setAdminMode(user === au && pw === (import.meta.env.VITE_ADMIN_PASSWORD || "admin")); } } }}
              >Unlock</span> to purchase this build
            </div>
          )}
        </div>

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
            const val = selections[cat.id];
            if (!val) return <SummaryItem key={cat.id} label={cat.label} item={null} />;
            if (Array.isArray(val)) {
              return val.map((item, idx) => (
                <SummaryItem key={`${cat.id}-${idx}`} label={cat.label} item={isOsFree ? { ...item, price: "0.00" } : item} />
              ));
            }
            const item = isOsFree ? { ...val, price: "0.00" } : val;
            return <SummaryItem key={cat.id} label={cat.label} item={item} />;
          })}
          {SUBCATEGORY_GROUPS.flatMap(g => g.categories).map(cat => {
            const val = selections[cat.id];
            if (!val) return null;
            if (Array.isArray(val)) {
              return val.map((item, idx) => (
                <SummaryItem key={`${cat.id}-${idx}`} label={cat.label} item={item} />
              ));
            }
            return <SummaryItem key={cat.id} label={cat.label} item={val} />;
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
            {BUILDER_CATEGORIES.flatMap(cat => {
              const val = selections[cat.id];
              const isOsFree = cat.id === "os" && buildType === "full";
              if (!val) return [{ cat, item: null, isOsFree }];
              if (Array.isArray(val)) return val.map(item => ({ cat, item, isOsFree }));
              return [{ cat, item: val, isOsFree }];
            }).map(({ cat, item, isOsFree }, idx) => {
              if (!item) {
                return (
                  <tr key={`${cat.id}-${idx}`} style={{ borderBottom: "1px solid #ddd" }}>
                    <td style={{ padding: "8px 6px", color: "#444" }}>{cat.label}</td>
                    <td style={{ padding: "8px 6px", color: "#888" }}>—</td>
                    <td style={{ padding: "8px 6px", textAlign: "right", color: "#888" }}>—</td>
                  </tr>
                );
              }
              const qty = item.qty || 1;
              const p = isOsFree ? 0 : parseFloat(item.price || 0) * qty;
              return (
                <tr key={`${cat.id}-${idx}`} style={{ borderBottom: "1px solid #ddd" }}>
                  <td style={{ padding: "8px 6px", color: "#444" }}>{cat.label}</td>
                  <td style={{ padding: "8px 6px", color: "#111", fontWeight: 600 }}>
                    {item.name}
                    {qty > 1 && <span style={{ color: "#0066cc" }}> ×{qty}</span>}
                    {isOsFree && <span style={{ color: "#888", fontWeight: 400, fontSize: "11px" }}> (included)</span>}
                  </td>
                  <td style={{ padding: "8px 6px", textAlign: "right", color: "#111" }}>£{p.toFixed(2)}</td>
                </tr>
              );
            })}
            {SUBCATEGORY_GROUPS.flatMap(g => g.categories).flatMap(cat => {
              const val = selections[cat.id];
              if (!val) return [];
              if (Array.isArray(val)) return val.map(item => ({ cat, item }));
              return [{ cat, item: val }];
            }).map(({ cat, item }, idx) => (
              <tr key={`sub-${cat.id}-${idx}`} style={{ borderBottom: "1px solid #ddd" }}>
                <td style={{ padding: "8px 6px", color: "#444" }}>{cat.label}</td>
                <td style={{ padding: "8px 6px", color: "#111", fontWeight: 600 }}>
                  {item.name}{(item.qty || 1) > 1 && <span style={{ color: "#0066cc" }}> ×{item.qty}</span>}
                </td>
                <td style={{ padding: "8px 6px", textAlign: "right", color: "#111" }}>£{((parseFloat(item.price) || 0) * (item.qty || 1)).toFixed(2)}</td>
              </tr>
            ))}
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
            {BUILDER_CATEGORIES.flatMap(cat => {
              const val = selections[cat.id];
              if (!val) return [];
              if (Array.isArray(val)) return val.map(item => ({ cat, item }));
              return [{ cat, item: val }];
            }).map(({ cat, item }, idx) => {
              const imageUrl = (item.image || "").split(",")[0];
              return (
                <tr key={`${cat.id}-${idx}`} style={{ borderBottom: "1px solid #ddd" }}>
                  <td style={{ padding: "8px 6px", color: "#444" }}>{cat.label}</td>
                  <td style={{ padding: "8px 6px", color: "#111", fontWeight: 600 }}>
                    {item.name}{(item.qty || 1) > 1 && <span style={{ color: "#0066cc" }}> ×{item.qty}</span>}
                  </td>
                  <td style={{ padding: "8px 6px", color: "#0066cc", fontSize: "11px", wordBreak: "break-all" }}>{imageUrl || "—"}</td>
                  <td style={{ padding: "8px 6px", textAlign: "right", color: "#111" }}>£{((parseFloat(item.price) || 0) * (item.qty || 1)).toFixed(2)}</td>
                </tr>
              );
            })}
            {SUBCATEGORY_GROUPS.flatMap(g => g.categories).flatMap(cat => {
              const val = selections[cat.id];
              if (!val) return [];
              if (Array.isArray(val)) return val.map(item => ({ cat, item }));
              return [{ cat, item: val }];
            }).map(({ cat, item }, idx) => {
              const imageUrl = (item.image || "").split(",")[0];
              return (
                <tr key={`sub-${cat.id}-${idx}`} style={{ borderBottom: "1px solid #ddd" }}>
                  <td style={{ padding: "8px 6px", color: "#444" }}>{cat.label}</td>
                  <td style={{ padding: "8px 6px", color: "#111", fontWeight: 600 }}>
                    {item.name}{(item.qty || 1) > 1 && <span style={{ color: "#0066cc" }}> ×{item.qty}</span>}
                  </td>
                  <td style={{ padding: "8px 6px", color: "#0066cc", fontSize: "11px", wordBreak: "break-all" }}>{imageUrl || "—"}</td>
                  <td style={{ padding: "8px 6px", textAlign: "right", color: "#111" }}>£{((parseFloat(item.price) || 0) * (item.qty || 1)).toFixed(2)}</td>
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
            Purchase This Build — Full Build
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: "12px", marginBottom: "20px" }}>
            <button onClick={generateInvoice} disabled={invoiceStatus !== "idle"}
              style={{
                padding: "14px", borderRadius: "8px", cursor: "pointer", border: "1px solid rgba(0,234,255,0.2)",
                background: "rgba(0,234,255,0.06)", color: invoiceStatus === "error" ? "#ef4444" : "#00eaff",
                fontFamily: "inherit", fontSize: "13px", fontWeight: 600, transition: "all 0.2s"
              }}>
              {invoiceStatus === "generating" ? "Generating PDF..." : invoiceStatus === "done" ? "✓ PDF Downloaded" : invoiceStatus === "error" ? "✕ Failed — Try Again" : "📄 Download Invoice (PDF)"}
            </button>

            <PayPalCheckout amount={bundledPrice} />
          </div>

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
            Total: £{bundledPrice.toLocaleString('en-GB')} incl. build, testing, delivery & warranty
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
