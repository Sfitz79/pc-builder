import React, { useState, useEffect, useRef } from 'react';
import { getBrand, isRGB, inferCoolerType } from '../utils/common';
import { generateBuildMockup } from '../utils/gemini';

const AIBuildVisual = ({ selections }) => {
  const [aiImage, setAiImage] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [generating, setGenerating] = useState(false);
  const attemptedRef = useRef(false);

  const pcCase = selections['case'] || {};
  const gpu = selections['gpu'] || {};
  const cooler = selections['cooler'] || {};
  const motherboard = selections['motherboard'] || {};
  const ram = selections['ram'] || {};
  const cpu = selections['cpu'] || {};
  const psu = selections['psu'] || {};
  const rawStorage = selections['ssd'];
  const storage = Array.isArray(rawStorage) ? (rawStorage[0] || {}) : (rawStorage || {});
  const storage2 = selections['mass-storage'] || {};
  const storage3 = {};
  const storage4 = {};

  const isAIO = inferCoolerType(cooler) === "AIO Liquid Cooler";
  const hasRGB = Object.values(selections).some(item => isRGB(item));
  const caseColor = String(pcCase.name || "").toLowerCase().includes("white") ? "white" : "black";
  
  const generateImage = async () => {
    if (!pcCase.name || !gpu.name) return;
    setGenerating(true);
    setLoading(true);
    setError(null);
    attemptedRef.current = true;
    try {
      const image = await generateBuildMockup(selections);
      if (image) {
        setAiImage(image);
      } else {
        setError("Failed to generate image");
      }
    } catch (err) {
      console.error("AI Visual Error:", err);
      setError("Failed to generate AI visual");
    } finally {
      setLoading(false);
      setGenerating(false);
    }
  };

  useEffect(() => {
    if (!aiImage && !attemptedRef.current && pcCase.name && gpu.name) {
      generateImage();
    }
  }, [pcCase.name, gpu.name]);

  // Extract primary images for fallback
  const getPrimaryImage = (item) => {
    if (!item?.image) return null;
    const images = String(item.image).split(",").filter(Boolean);
    const path = images[0];
    if (!path) return null;
    if (path.startsWith("http")) return path;
    if (path.startsWith("/")) return path;
    if (path.startsWith("thumbnails/")) return "/" + path;
    return "/thumbnails/" + path;
  };

  const caseImg = getPrimaryImage(pcCase);
  const gpuImg = getPrimaryImage(gpu);
  const coolerImg = getPrimaryImage(cooler);

  return (
    <div className="ai-visual-container" style={{
      position: 'relative',
      width: '100%',
      height: '500px',
      background: '#050508',
      borderRadius: '16px',
      overflow: 'hidden',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      border: '1px solid rgba(0, 234, 255, 0.3)',
      boxShadow: 'inset 0 0 50px rgba(0, 0, 0, 0.9), 0 0 20px rgba(0, 234, 255, 0.1)'
    }}>
      {/* Background Grid / Tech Effect */}
      <div style={{
        position: 'absolute',
        inset: 0,
        backgroundImage: 'linear-gradient(rgba(0, 234, 255, 0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(0, 234, 255, 0.03) 1px, transparent 1px)',
        backgroundSize: '20px 20px',
        opacity: 0.5,
        pointerEvents: 'none'
      }} />

      {/* Case Visual */}
      <div style={{
        position: 'relative',
        width: '100%',
        maxWidth: '500px',
        height: '100%',
        maxHeight: '460px',
        zIndex: 2,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center'
      }}>
        {aiImage ? (
          <img 
            src={aiImage} 
            alt="AI Generated PC Build" 
            style={{ 
              width: '100%', 
              height: '100%', 
              objectFit: 'contain',
              borderRadius: '8px',
              boxShadow: `0 0 30px ${hasRGB ? 'rgba(0, 234, 255, 0.4)' : 'rgba(0,0,0,0.5)'}`
            }} 
          />
        ) : caseImg ? (
          <img 
            src={caseImg} 
            alt="PC Case" 
            style={{ 
              width: '100%', 
              height: '100%', 
              objectFit: 'contain',
              filter: `drop-shadow(0 0 15px ${hasRGB ? 'rgba(0, 234, 255, 0.4)' : 'rgba(255, 255, 255, 0.1)'})`,
              opacity: loading ? 0.3 : 1
            }} 
          />
        ) : (
          <div style={{
            width: '300px',
            height: '380px',
            border: `3px solid ${caseColor === 'white' ? '#eee' : '#333'}`,
            borderRadius: '8px',
            background: caseColor === 'white' ? 'rgba(255,255,255,0.05)' : 'rgba(20,20,25,0.8)',
            position: 'relative',
            boxShadow: `0 0 30px ${hasRGB ? 'rgba(0, 234, 255, 0.2)' : 'black'}`
          }}>
             {/* Internal Components Stylized */}
             <div style={{ position: 'absolute', top: '40px', left: '40px', right: '40px', bottom: '60px', border: '1px solid rgba(255,255,255,0.05)', background: '#111' }}>
                {/* Motherboard area */}
                <div style={{ position: 'absolute', top: '20px', left: '20px', right: '10px', bottom: '20px', background: '#1a1a1a', borderRadius: '4px' }}>
                    {/* RAM */}
                    <div style={{ position: 'absolute', top: '30px', right: '40px', display: 'flex', gap: '4px' }}>
                        {[1,2].map(i => (
                            <div key={i} style={{ 
                                width: '4px', 
                                height: '50px', 
                                background: isRGB(ram) ? 'linear-gradient(to bottom, #00eaff, #ff005e)' : '#444',
                                boxShadow: isRGB(ram) ? '0 0 8px #00eaff' : 'none'
                            }} />
                        ))}
                    </div>

                    {/* CPU Cooler */}
                    <div style={{ 
                        position: 'absolute', 
                        top: '25px', 
                        left: '40px', 
                        width: '60px', 
                        height: '60px', 
                        background: '#222',
                        borderRadius: isAIO ? '4px' : '50%',
                        border: '1px solid #333',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        zIndex: 3
                    }}>
                        <div style={{ 
                            width: '40px', 
                            height: '40px', 
                            borderRadius: '50%', 
                            border: `2px solid ${isRGB(cooler) ? '#00eaff' : '#444'}`,
                            boxShadow: isRGB(cooler) ? '0 0 10px #00eaff' : 'none'
                        }} />
                    </div>

                    {/* GPU */}
                    <div style={{ 
                        position: 'absolute', 
                        top: '100px', 
                        left: '10px', 
                        width: '160px', 
                        height: '35px', 
                        background: '#252525', 
                        borderRadius: '2px',
                        borderBottom: `2px solid ${isRGB(gpu) ? '#ff005e' : '#333'}`,
                        boxShadow: isRGB(gpu) ? '0 5px 15px rgba(255, 0, 94, 0.3)' : 'none',
                        zIndex: 4
                    }}>
                        <div style={{ fontSize: '8px', padding: '4px', color: '#555', overflow: 'hidden' }}>{gpu.name}</div>
                    </div>
                </div>
             </div>
          </div>
        )}
      </div>

      {/* AI Scanning / Hud Elements */}
      <div style={{
        position: 'absolute',
        top: '20px',
        left: '20px',
        color: '#00eaff',
        fontFamily: 'monospace',
        fontSize: '12px',
        zIndex: 5,
        textShadow: '0 0 5px #00eaff'
      }}>
        <div>&gt; AI_VISUAL_RECONSTRUCTION_INITIATED</div>
        <div style={{ opacity: 0.7 }}>&gt; MATCHING_COMPONENTS: 100%</div>
        <div style={{ opacity: 0.7 }}>&gt; CASE: {pcCase.name || "GENERIC"}</div>
        <div style={{ opacity: 0.7 }}>&gt; GPU: {gpu.name || "GENERIC"}</div>
        <div style={{ marginTop: '10px', color: '#ff005e' }}>
          {generating ? "[ GENERATING AI MOCKUP... ]" : error ? `[ ERROR: ${error} ]` : aiImage ? "[ AI MOCKUP GENERATED ]" : "[ SCANNING FOR OPTIMAL AIRFLOW ]"}
        </div>
        {!aiImage && !generating && (
          <button 
            onClick={generateImage}
            style={{
              marginTop: '10px',
              padding: '8px 16px',
              background: 'linear-gradient(135deg, #00eaff, #ff005e)',
              border: 'none',
              borderRadius: '4px',
              color: '#fff',
              cursor: 'pointer',
              fontFamily: 'monospace',
              fontSize: '11px'
            }}
          >
            GENERATE AI IMAGE
          </button>
        )}
      </div>

      {/* Floating Callouts */}
      <div style={{
        position: 'absolute',
        top: '150px',
        right: '40px',
        textAlign: 'right',
        zIndex: 5
      }}>
        <div style={{ color: '#00eaff', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '1px' }}>Thermal Solution</div>
        <div style={{ color: '#fff', fontSize: '14px', fontWeight: 'bold' }}>{isAIO ? "LIQUID COOLED" : "AIR COOLED"}</div>
        <div style={{ width: '40px', height: '1px', background: '#00eaff', marginLeft: 'auto', marginTop: '4px' }} />
      </div>

      <div style={{
        position: 'absolute',
        bottom: '80px',
        left: '40px',
        zIndex: 5
      }}>
        <div style={{ color: '#ff005e', fontSize: '10px', textTransform: 'uppercase', letterSpacing: '1px' }}>Lighting Profile</div>
        <div style={{ color: '#fff', fontSize: '14px', fontWeight: 'bold' }}>{hasRGB ? "RGB SYNC ENABLED" : "STEALTH MODE"}</div>
        <div style={{ width: '40px', height: '1px', background: '#ff005e', marginTop: '4px' }} />
      </div>

      {/* Glitch Overlay Effect */}
      <div className="ai-glitch-overlay" />

      <style>{`
        .ai-glitch-overlay {
          position: absolute;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06));
          background-size: 100% 2px, 3px 100%;
          pointer-events: none;
          z-index: 10;
          opacity: 0.2;
        }

        @keyframes scanline {
          0% { top: 0; }
          100% { top: 100%; }
        }

        .ai-visual-container::after {
          content: "";
          position: absolute;
          top: 0;
          left: 0;
          width: 100%;
          height: 15px;
          background: rgba(0, 234, 255, 0.2);
          box-shadow: 0 0 15px rgba(0, 234, 255, 0.4);
          animation: scanline 3s linear infinite;
          z-index: 15;
        }
      `}</style>
    </div>
  );
};

export default AIBuildVisual;
