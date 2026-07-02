import { useEffect, useRef, useState } from "react";
import { assetPath } from "../utils/assetPath";

const POPUPS = [
  { id: 1, title: "Get YOUR Gamers Edge", subtitle: "In 3 Steps!!!" },
  { id: 2, title: "1. Select Parts & Budget", desc: "Choose your components or let the builder auto-optimize." },
  { id: 3, title: "2. Get Price & Performance Summary", desc: "Instant FPS estimates + your visual PC build preview." },
  { id: 4, title: "3. Select Purchase & Wait for Delivery", desc: "Your custom gaming rig delivered to your door." },
];

export default function IntroAnimation({ price = "£1,249", performance = "1440p Ultra • 165 FPS", onFinish }) {
  const [stage, setStage] = useState("logo");
  const [finished, setFinished] = useState(false);
  const [hidden, setHidden] = useState(false);
  const [partsSelected, setPartsSelected] = useState(Array(6).fill(false));
  const [caseAssembled, setCaseAssembled] = useState(false);
  const [caseRevealed, setCaseRevealed] = useState(false);
  const [metricsVisible, setMetricsVisible] = useState(false);
  const [tickDrawn, setTickDrawn] = useState(false);
  const [popup, setPopup] = useState(0);
  const partsRef = useRef([]);

  useEffect(() => {
    const t = (ms, fn) => {
      const id = setTimeout(fn, ms);
      return id;
    };
    const ids = [];

    ids.push(t(500, () => setPopup(1)));

    ids.push(t(3000, () => {
      setPopup(2);
      setStage("parts");
      ids.push(t(1000, () => {
        setPartsSelected([true, true, true, true, true, true]);
      }));
    }));

    ids.push(t(6000, () => {
      setPopup(3);
      setStage("caseEmpty");
      setCaseAssembled(true);
    }));

    ids.push(t(9000, () => {
      setPopup(4);
      setStage("caseFinal");
      setCaseRevealed(true);
    }));

    ids.push(t(12000, () => {
      setPopup(0);
      setStage("metrics");
      setMetricsVisible(true);
      setTickDrawn(true);
    }));

    ids.push(t(15000, () => {
      setFinished(true);
      ids.push(t(1000, () => {
        setHidden(true);
        onFinish?.();
      }));
    }));

    return () => ids.forEach(clearTimeout);
  }, [onFinish]);

  return (
    <div id="pcv-intro" className={finished ? "pcv-finished" : ""} style={{ display: hidden ? "none" : undefined }}>
      <div className="pcv-overlay" />

      {/* Popups */}
      <div id="pcv-popups">
        {POPUPS.map(p => (
          <div key={p.id} className={`pcv-popup ${popup === p.id ? "active" : ""}`}>
            <h1>{p.title}</h1>
            {p.subtitle && <h2>{p.subtitle}</h2>}
            {p.desc && <p>{p.desc}</p>}
          </div>
        ))}
      </div>

      {/* Stage 1: Logo */}
      <div className={`pcv-stage pcv-stage-logo ${stage === "logo" ? "active" : ""}`}>
        <img src={assetPath("/intro/pctg.png")} alt="PCTG Logo" className="pcv-logo" />
      </div>

      {/* Stage 2: Parts */}
      <div className={`pcv-stage pcv-stage-parts ${stage === "parts" ? "active" : ""}`}>
        {[
          { img: "cpu.png", label: "CPU" },
          { img: "gpu.png", label: "GPU" },
          { img: "ram.png", label: "RAM" },
          { img: "ssd.png", label: "SSD" },
          { img: "cooler.png", label: "Cooler" },
          { img: "psu.png", label: "PSU" },
        ].map((part, i) => (
          <div
            key={part.label}
            ref={(el) => (partsRef.current[i] = el)}
            className={`pcv-part ${partsSelected[i] ? "selected" : ""}`}
            style={{
              transitionDelay: `${150 * i}ms`,
              opacity: 1,
              transform: "translateY(0)",
            }}
          >
            <img src={assetPath(`/intro/${part.img}`)} alt={part.label} />
            <span>{part.label}</span>
          </div>
        ))}
      </div>

      {/* Stage 3: Empty case */}
      <div className={`pcv-stage pcv-stage-case-empty ${stage === "caseEmpty" ? "active" : ""}`}>
        <div className={`pcv-case ${caseAssembled ? "assembled" : ""}`}>
          <img src={assetPath("/intro/empty.png")} alt="Empty Case" />
          <div className="pcv-case-glow" />
        </div>
      </div>

      {/* Stage 4: Final build */}
      <div className={`pcv-stage pcv-stage-case-final ${stage === "caseFinal" ? "active" : ""}`}>
        <div className={`pcv-case pcv-case-final ${caseRevealed ? "reveal" : ""}`}>
          <img src={assetPath("/intro/final.png")} alt="Completed Build" />
          <div className="pcv-case-glow" />
        </div>
      </div>

      {/* Stage 5: Tick + metrics */}
      <div className={`pcv-stage pcv-stage-metrics ${stage === "metrics" ? "active" : ""}`}>
        <div className="pcv-tick-wrap">
          <div className={`pcv-tick-circle ${metricsVisible ? "show" : ""}`}>
            <div className={`pcv-tick ${tickDrawn ? "draw" : ""}`} />
          </div>
        </div>
        <div className={`pcv-metrics ${metricsVisible ? "show" : ""}`}>
          <div className="pcv-price">{price}</div>
          <div className="pcv-performance">{performance}</div>
        </div>
      </div>

      <style>{`
        #pcv-intro {
          position: fixed;
          inset: 0;
          width: 100%;
          height: 100vh;
          background: radial-gradient(circle at top, #141b2b 0%, #05060a 60%);
          color: #fff;
          overflow: hidden;
          font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
          z-index: 9999;
        }

        .pcv-overlay {
          position: absolute;
          inset: 0;
          background: #05060a;
          opacity: 0;
          pointer-events: none;
          transition: opacity 0.8s ease-in-out;
        }

        #pcv-intro.pcv-finished .pcv-overlay {
          opacity: 1;
        }

        .pcv-stage {
          position: absolute;
          inset: 0;
          display: flex;
          align-items: center;
          justify-content: center;
          opacity: 0;
          transform: scale(0.95);
          transition: opacity 0.6s ease, transform 0.6s ease;
          pointer-events: none;
        }

        .pcv-stage.active {
          opacity: 1;
          transform: scale(1);
        }

        .pcv-stage-logo .pcv-logo {
          width: 260px;
          filter: drop-shadow(0 0 25px rgba(255, 0, 80, 0.8));
          transform: scale(0.7);
          animation: pcv-logo-pop 1.4s ease-out forwards;
        }

        @keyframes pcv-logo-pop {
          0% { opacity: 0; transform: scale(0.4); }
          40% { opacity: 1; transform: scale(1.1); }
          100% { opacity: 1; transform: scale(1); }
        }

        .pcv-stage-parts {
          flex-direction: row;
          flex-wrap: wrap;
          gap: 24px;
          padding: 0 40px;
        }

        .pcv-part {
          width: 150px;
          background: rgba(10, 14, 24, 0.9);
          border-radius: 16px;
          padding: 10px;
          border: 1px solid rgba(0, 255, 170, 0.15);
          box-shadow: 0 0 18px rgba(0, 0, 0, 0.6);
          display: flex;
          flex-direction: column;
          align-items: center;
          transform: translateY(40px);
          opacity: 0;
          transition: opacity 0.5s ease, transform 0.5s ease, border-color 0.4s ease, box-shadow 0.4s ease;
        }

        .pcv-part img {
          width: 100%;
          border-radius: 12px;
          object-fit: cover;
        }

        .pcv-part span {
          margin-top: 8px;
          font-size: 0.8rem;
          letter-spacing: 0.06em;
          text-transform: uppercase;
          color: #a9b4d9;
        }

        .pcv-part.selected {
          border-color: rgba(0, 255, 170, 0.9);
          box-shadow: 0 0 25px rgba(0, 255, 170, 0.4);
          transform: translateY(0) scale(1.05);
        }

        .pcv-stage-case-empty .pcv-case,
        .pcv-stage-case-final .pcv-case {
          position: relative;
          width: 360px;
          border-radius: 24px;
          overflow: hidden;
          box-shadow: 0 0 40px rgba(0, 0, 0, 0.8);
          transform: translateY(40px);
          opacity: 0;
        }

        .pcv-case img {
          width: 100%;
          display: block;
        }

        .pcv-case-glow {
          position: absolute;
          inset: 0;
          background: radial-gradient(circle at center, rgba(0, 255, 170, 0.35), transparent 60%);
          mix-blend-mode: screen;
          opacity: 0;
        }

        .pcv-case.assembled {
          animation: pcv-case-rise 0.9s ease-out forwards;
        }

        .pcv-case.assembled .pcv-case-glow {
          animation: pcv-case-glow 1.4s ease-out forwards;
        }

        .pcv-case-final.reveal {
          animation: pcv-case-final-rise 0.9s ease-out forwards;
        }

        .pcv-case-final.reveal .pcv-case-glow {
          animation: pcv-case-glow 1.4s ease-out forwards;
        }

        @keyframes pcv-case-rise {
          0% { opacity: 0; transform: translateY(60px) scale(0.9); }
          100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes pcv-case-final-rise {
          0% { opacity: 0; transform: translateY(40px) scale(0.95); }
          100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes pcv-case-glow {
          0% { opacity: 0; }
          40% { opacity: 1; }
          100% { opacity: 0.6; }
        }

        .pcv-stage-metrics {
          flex-direction: column;
          gap: 18px;
        }

        .pcv-tick-wrap {
          display: flex;
          justify-content: center;
        }

        .pcv-tick-circle {
          width: 80px;
          height: 80px;
          border-radius: 50%;
          border: 3px solid rgba(0, 255, 170, 0.9);
          display: flex;
          align-items: center;
          justify-content: center;
          box-shadow: 0 0 25px rgba(0, 255, 170, 0.7);
          transform: scale(0.6);
          opacity: 0;
        }

        .pcv-tick-circle.show {
          animation: pcv-tick-circle-pop 0.7s ease-out forwards;
        }

        @keyframes pcv-tick-circle-pop {
          0% { opacity: 0; transform: scale(0.4); }
          60% { opacity: 1; transform: scale(1.1); }
          100% { opacity: 1; transform: scale(1); }
        }

        .pcv-tick {
          width: 26px;
          height: 14px;
          border-left: 4px solid #00ffaa;
          border-bottom: 4px solid #00ffaa;
          transform: rotate(-45deg);
          transform-origin: left bottom;
          opacity: 0;
        }

        .pcv-tick.draw {
          animation: pcv-tick-draw 0.5s ease-out forwards 0.2s;
        }

        @keyframes pcv-tick-draw {
          0% { opacity: 0; transform: rotate(-45deg) scaleX(0); }
          100% { opacity: 1; transform: rotate(-45deg) scaleX(1); }
        }

        .pcv-metrics {
          text-align: center;
          opacity: 0;
          transform: translateY(20px);
        }

        .pcv-metrics.show {
          animation: pcv-metrics-rise 0.7s ease-out forwards 0.3s;
        }

        @keyframes pcv-metrics-rise {
          0% { opacity: 0; transform: translateY(20px); }
          100% { opacity: 1; transform: translateY(0); }
        }

        .pcv-price {
          font-size: 1.4rem;
          font-weight: 600;
          color: #00ffaa;
        }

        .pcv-performance {
          font-size: 0.95rem;
          color: #c4c9e6;
        }

        #pcv-popups {
          position: absolute;
          top: 0;
          left: 0;
          right: 0;
          z-index: 10;
          display: flex;
          justify-content: center;
          pointer-events: none;
        }

        .pcv-popup {
          position: absolute;
          top: 40px;
          background: linear-gradient(135deg, rgba(0, 234, 255, 0.12), rgba(255, 0, 94, 0.08));
          backdrop-filter: blur(14px);
          border: 1px solid rgba(0, 234, 255, 0.2);
          border-radius: 16px;
          padding: 18px 32px;
          max-width: 520px;
          width: 90%;
          text-align: center;
          opacity: 0;
          transform: translateY(-30px) scale(0.92);
          transition: opacity 0.5s ease, transform 0.5s ease;
          pointer-events: none;
        }

        .pcv-popup.active {
          opacity: 1;
          transform: translateY(0) scale(1);
        }

        .pcv-popup h1 {
          font-size: 1.1rem;
          font-weight: 700;
          color: #fff;
          margin: 0 0 2px;
          letter-spacing: 0.3px;
        }

        .pcv-popup h2 {
          font-size: 0.9rem;
          font-weight: 600;
          color: #00eaff;
          margin: 0;
          letter-spacing: 0.5px;
        }

        .pcv-popup p {
          font-size: 0.8rem;
          color: #a9b4d9;
          margin: 4px 0 0;
        }

        @media (max-width: 600px) {
          .pcv-part {
            width: 100px;
            padding: 6px;
          }
          .pcv-stage-parts {
            gap: 12px;
            padding: 0 16px;
          }
          .pcv-stage-case-empty .pcv-case,
          .pcv-stage-case-final .pcv-case {
            width: 260px;
          }
        }
      `}</style>
    </div>
  );
}
