const NeonPC = ({ size = 200, coolerType, gpuModel, ramType }) => {
  const isAIO = coolerType === "AIO Liquid Cooler";
  const coolerSize = isAIO ? 1.5 : 1.0;
  
  // Detect GPU type for different visual styles
  const isRtx = gpuModel?.toUpperCase().includes("RTX");
  const isGtx = gpuModel?.toUpperCase().includes("GTX");
  const isRadeon = gpuModel?.toUpperCase().includes("RX") || gpuModel?.toUpperCase().includes("RADEON");
  
  // RAM count/type logic
  const isRgbRam = ramType?.toUpperCase().includes("RGB");
  
  return (
    <div className="neon-pc-container">
      <svg
        viewBox="0 0 200 200"
        width={size}
        height={size}
        className="neon-pc-svg"
        xmlns="http://www.w3.org/2000/svg"
      >
        {/* Monitor */}
        <rect
          className="neon-line"
          x="110"
          y="60"
          width="80"
          height="50"
          rx="2"
          fill="none"
          stroke="#00eaff"
          strokeWidth="2"
        />
        <path
          className="neon-line"
          d="M140,110 L160,110 L155,125 L145,125 Z"
          fill="none"
          stroke="#00eaff"
          strokeWidth="2"
        />
        <line
          className="neon-line"
          x1="135"
          y1="125"
          x2="165"
          y2="125"
          stroke="#00eaff"
          strokeWidth="2"
        />

        {/* PC Case */}
        <rect
          className="neon-line-alt"
          x="20"
          y="40"
          width="70"
          height="120"
          rx="4"
          fill="none"
          stroke="#ff005e"
          strokeWidth="3"
        />
        {/* Window/Interior */}
        <rect
          className="neon-line-slow"
          x="30"
          y="50"
          width="50"
          height="90"
          rx="2"
          fill="none"
          stroke="#00eaff"
          strokeWidth="1"
          strokeDasharray="4 2"
        />
        
        {/* Motherboard / Components Interior */}
        <rect
          className="neon-line"
          x="35"
          y="55"
          width="40"
          height="80"
          fill="rgba(0, 234, 255, 0.05)"
          stroke="none"
        />

        {/* RAM Sticks */}
        <rect
          className={isRgbRam ? "neon-pulse" : "neon-line"}
          x="65"
          y="60"
          width="3"
          height="30"
          fill="none"
          stroke={isRgbRam ? "#00eaff" : "#ff005e"}
          strokeWidth="1"
        />
        <rect
          className={isRgbRam ? "neon-pulse" : "neon-line"}
          x="70"
          y="60"
          width="3"
          height="30"
          fill="none"
          stroke={isRgbRam ? "#00eaff" : "#ff005e"}
          strokeWidth="1"
        />

        {/* GPU */}
        {gpuModel && (
          <g>
            <rect
              className="neon-line-alt"
              x="35"
              y="100"
              width="45"
              height="10"
              rx="1"
              fill="none"
              stroke="#ff005e"
              strokeWidth="2"
            />
            {/* GPU Fans / Branding */}
            <line x1="45" y1="105" x2="70" y2="105" stroke={isRadeon ? "#ff0000" : (isRtx || isGtx) ? "#76b900" : "#00eaff"} strokeWidth="1" strokeDasharray="2 1" />
          </g>
        )}

        {/* Fans/Cooler */}
        {/* Top Fan (Representing AIO radiator fans if AIO) */}
        <circle 
          className="neon-pulse" 
          cx="55" 
          cy="70" 
          r={10 * coolerSize} 
          fill="none" 
          stroke={isAIO ? "#ff005e" : "#00eaff"} 
          strokeWidth="1" 
        />
        {/* Middle Fan (Standard or block) */}
        <circle 
          className="neon-pulse" 
          cx="55" 
          cy="100" 
          r={10 * (isAIO ? 0.8 : 1.0)} 
          fill="none" 
          stroke="#00eaff" 
          strokeWidth="1" 
        />
        {/* Bottom Fan */}
        <circle 
          className="neon-pulse" 
          cx="55" 
          cy="130" 
          r="10" 
          fill="none" 
          stroke="#00eaff" 
          strokeWidth="1" 
        />

        {/* Keyboard */}
        <rect
          className="neon-line"
          x="110"
          y="135"
          width="80"
          height="10"
          rx="2"
          fill="none"
          stroke="#ff005e"
          strokeWidth="2"
        />

        {/* Mouse */}
        <rect
          className="neon-line"
          x="165"
          y="150"
          width="15"
          height="10"
          rx="5"
          fill="none"
          stroke="#00eaff"
          strokeWidth="2"
        />
      </svg>
      <style>{`
        .neon-pc-container {
          display: flex;
          justify-content: center;
          align-items: center;
          padding: 20px;
          background: radial-gradient(circle, rgba(0, 234, 255, 0.05) 0%, transparent 70%);
        }
        .neon-pc-svg {
          filter: drop-shadow(0 0 8px rgba(0, 234, 255, 0.3));
        }
        .neon-line {
          animation: pulse-cyan 2s infinite alternate;
        }
        .neon-line-alt {
          animation: pulse-magenta 2.5s infinite alternate;
        }
        .neon-line-slow {
          animation: flicker 4s infinite;
        }
        .neon-pulse {
          animation: fan-spin 3s infinite linear, pulse-cyan 1.5s infinite alternate;
          transform-origin: center;
        }

        @keyframes pulse-cyan {
          0% { opacity: 0.5; filter: drop-shadow(0 0 2px #00eaff); }
          100% { opacity: 1; filter: drop-shadow(0 0 12px #00eaff); }
        }
        @keyframes pulse-magenta {
          0% { opacity: 0.5; filter: drop-shadow(0 0 2px #ff005e); }
          100% { opacity: 1; filter: drop-shadow(0 0 12px #ff005e); }
        }
        @keyframes flicker {
          0%, 100% { opacity: 0.8; }
          50% { opacity: 0.4; }
          52% { opacity: 0.9; }
          54% { opacity: 0.3; }
        }
        @keyframes fan-spin {
          from { transform: rotate(0deg); }
          to { transform: rotate(360deg); }
        }
      `}</style>
    </div>
  );
};

export default NeonPC;
