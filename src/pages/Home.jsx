import { Link } from "react-router-dom";
import { assetPath } from "../utils/assetPath";
import "./Home.css";

const SOCIAL_LINKS = [
  { name: "Facebook", url: "https://www.facebook.com/pctechguyonline", icon: "https://cdn.simpleicons.org/facebook/1877F2", color: "#1877F2" },
  { name: "Instagram", url: "https://www.instagram.com/pctechguyonline", icon: "https://cdn.simpleicons.org/instagram/E4405F", color: "#E4405F" },
  { name: "X", url: "https://x.com/pctechguyonline", icon: "https://cdn.simpleicons.org/x/000000", color: "#000000" },
  { name: "TikTok", url: "https://www.tiktok.com/@pctechguyonline", icon: "https://cdn.simpleicons.org/tiktok/000000", color: "#000000" },
  { name: "YouTube", url: "https://www.youtube.com/channel/UCKZVSHOWfJAJKdDr73pmNnA", icon: "https://cdn.simpleicons.org/youtube/FF0000", color: "#FF0000" },
  { name: "Discord", url: "https://discord.gg/w2Y7VBMAg", icon: "https://cdn.simpleicons.org/discord/5865F2", color: "#5865F2" },
  { name: "Xbox", url: "https://www.xbox.com/en-GB/play/user/DGINGERBREDMAN", icon: "https://cdn.simpleicons.org/xbox/107C10", color: "#107C10" },
  { name: "Steam", url: "https://steamcommunity.com/profiles/76561197979525265", icon: "https://cdn.simpleicons.org/steam/1B2838", color: "#1B2838" },
  { name: "Epic Games", url: "https://store.epicgames.com/u/594528bea06d4a60b077ebbb45fe2579", icon: "https://cdn.simpleicons.org/epicgames/2F2D2E", color: "#2F2D2E" }
];

export default function Home() {
  return (
    <div className="home">
      <div className="home-hero">
        <img src={assetPath("/pctg-main.png")} alt="PcTechGuyOnline" className="home-avatar" onError={e => { e.target.style.display = "none"; }} />
        <h1>PCTechGuyOnline.com</h1>
        <p className="tagline">Get Your Gamers Edge © with a custom PC built for you</p>
        <img src={assetPath("/pctg-banner.png")} alt="Gaming PCs" className="home-banner" onError={e => { e.target.style.display = "none"; }} />
        <div style={{ display: "flex", gap: "12px", justifyContent: "center", flexWrap: "wrap" }}>
          <Link to="/ai-generator" className="cta-btn" style={{ fontSize: "0.9rem", padding: "14px 28px" }}>AI Build Generator</Link>
          <Link to="/builder" className="cta-btn" style={{ fontSize: "0.9rem", padding: "14px 28px", background: "linear-gradient(135deg, #ff005e, #00eaff)" }}>Manual Builder</Link>
        </div>
      </div>

      <div className="home-stats">
        <div className="home-stat">
          <div className="stat-number">500+</div>
          <div className="stat-label">PCs Built</div>
        </div>
        <div className="home-stat">
          <div className="stat-number">4.9★</div>
          <div className="stat-label">Customer Rating</div>
        </div>
        <div className="home-stat">
          <div className="stat-number">UK</div>
          <div className="stat-label">Based & Shipped</div>
        </div>
      </div>

      <div className="home-guide">
        <h2>How to Build Your PC</h2>
        <div className="step">
          <div className="step-num">1</div>
          <div className="step-content">
            <h3>Choose Your Components</h3>
            <p>Click on "+ Choose" next to each component to browse compatible parts. Our compatibility filter ensures everything works together.</p>
          </div>
        </div>
        <div className="step">
          <div className="step-num">2</div>
          <div className="step-content">
            <h3>Check Compatibility</h3>
            <p>The builder automatically checks that your selected CPU, motherboard, RAM, GPU, and other components are compatible.</p>
          </div>
        </div>
        <div className="step">
          <div className="step-num">3</div>
          <div className="step-content">
            <h3>Review & Order</h3>
            <p>Review your complete build with a bundled price including professional build service, testing, and Royal Mail delivery.</p>
          </div>
        </div>
      </div>

      <div className="home-features">
        <Link to="/ai-generator" className="home-feature" style={{ textDecoration: "none", display: "block", padding: "20px", background: "rgba(255,255,255,0.02)", borderRadius: "10px", border: "1px solid rgba(0,234,255,0.08)", textAlign: "center", transition: "all 0.3s" }}>
          <div style={{ fontSize: "32px", marginBottom: "8px" }}>🤖</div>
          <h3 style={{ color: "#00eaff", fontSize: "14px", margin: "0 0 6px", textTransform: "uppercase" }}>AI Build Generator</h3>
          <p style={{ color: "#888", fontSize: "12px", margin: 0, lineHeight: "1.5" }}>Enter your budget and use case — AI picks the perfect parts</p>
        </Link>
        <Link to="/fps-calculator" className="home-feature" style={{ textDecoration: "none", display: "block", padding: "20px", background: "rgba(255,255,255,0.02)", borderRadius: "10px", border: "1px solid rgba(0,234,255,0.08)", textAlign: "center", transition: "all 0.3s" }}>
          <div style={{ fontSize: "32px", marginBottom: "8px" }}>🎮</div>
          <h3 style={{ color: "#00eaff", fontSize: "14px", margin: "0 0 6px", textTransform: "uppercase" }}>FPS Calculator</h3>
          <p style={{ color: "#888", fontSize: "12px", margin: 0, lineHeight: "1.5" }}>Estimate FPS for 20+ games at any resolution</p>
        </Link>
        <Link to="/bottleneck-calculator" className="home-feature" style={{ textDecoration: "none", display: "block", padding: "20px", background: "rgba(255,255,255,0.02)", borderRadius: "10px", border: "1px solid rgba(0,234,255,0.08)", textAlign: "center", transition: "all 0.3s" }}>
          <div style={{ fontSize: "32px", marginBottom: "8px" }}>🔍</div>
          <h3 style={{ color: "#00eaff", fontSize: "14px", margin: "0 0 6px", textTransform: "uppercase" }}>Bottleneck Calculator</h3>
          <p style={{ color: "#888", fontSize: "12px", margin: 0, lineHeight: "1.5" }}>Check CPU/GPU bottleneck percentages</p>
        </Link>
        <Link to="/compare" className="home-feature" style={{ textDecoration: "none", display: "block", padding: "20px", background: "rgba(255,255,255,0.02)", borderRadius: "10px", border: "1px solid rgba(0,234,255,0.08)", textAlign: "center", transition: "all 0.3s" }}>
          <div style={{ fontSize: "32px", marginBottom: "8px" }}>⚖️</div>
          <h3 style={{ color: "#00eaff", fontSize: "14px", margin: "0 0 6px", textTransform: "uppercase" }}>Component Comparison</h3>
          <p style={{ color: "#888", fontSize: "12px", margin: 0, lineHeight: "1.5" }}>Compare parts side by side to find the best</p>
        </Link>
        <Link to="/builds" className="home-feature" style={{ textDecoration: "none", display: "block", padding: "20px", background: "rgba(255,255,255,0.02)", borderRadius: "10px", border: "1px solid rgba(0,234,255,0.08)", textAlign: "center", transition: "all 0.3s" }}>
          <div style={{ fontSize: "32px", marginBottom: "8px" }}>📦</div>
          <h3 style={{ color: "#00eaff", fontSize: "14px", margin: "0 0 6px", textTransform: "uppercase" }}>Build History</h3>
          <p style={{ color: "#888", fontSize: "12px", margin: 0, lineHeight: "1.5" }}>Save, load, and export your builds</p>
        </Link>
        <div className="home-feature">
          <div className="feature-icon">🔗</div>
          <h3>Shareable Link</h3>
          <p>Save your build and share it with friends using a unique link.</p>
        </div>
        <div className="home-feature">
          <div className="feature-icon">✅</div>
          <h3>Compatibility Filter</h3>
          <p>Only compatible parts are shown, ensuring your build works perfectly.</p>
        </div>
        <div className="home-feature">
          <div className="feature-icon">🔌</div>
          <h3>Bundled Pricing</h3>
          <p>See the complete price including build, testing, and delivery — no hidden fees.</p>
        </div>
        <div className="home-feature">
          <div className="feature-icon">🤖</div>
          <h3>AI Assistant</h3>
          <p>Get real-time advice from PCTG, your AI product advisor.</p>
        </div>
      </div>

      <div className="home-social">
        <h3>Connect With Us</h3>
        <div className="social-links">
          {SOCIAL_LINKS.map(social => (
            <a key={social.name} href={social.url} target="_blank" rel="noopener noreferrer" className="social-link" title={social.name}>
              <img src={social.icon} alt={social.name} />
            </a>
          ))}
        </div>
      </div>
    </div>
  );
}
