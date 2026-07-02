import { Link } from "react-router-dom";
import { assetPath } from "../utils/assetPath";
import versionData from "../version.json";
import "./Footer.css";

export default function Footer() {
  const buildDate = new Date(versionData.buildDate);
  const dateStr = buildDate.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });

  return (
    <footer className="site-footer">
      <div className="footer-version">v{versionData.version} • Updated {dateStr}</div>
      <div className="footer-inner">
        <div className="footer-brand">
          <Link to="/" className="footer-logo">
            <img src={assetPath("/pctg-logo.png")} alt="PcTechGuyOnline" style={{ height: "32px", width: "auto", display: "block" }} />
          </Link>
          <p>Browse, Discover, Customize, Build Your PC — All in one place! PC Builder is your go-to platform for building your PC from scratch.</p>
          <p>Reach us at: <a href="mailto:info@pctechguyonline.com" style={{ color: "#00eaff" }}>info@pctechguyonline.com</a></p>
          <p className="disclaimer">Product price & specifications may change. Always verify on the retailer's website before purchasing. Compatibility checks are highly accurate but should be verified before ordering.</p>
        </div>

        <div className="footer-col">
          <h4>Useful Links</h4>
          <Link to="/builder">PC Builder</Link>
          <Link to="/admin">Admin Panel</Link>
          <a href="https://www.pctechguyonline.com" target="_blank" rel="noopener noreferrer">PcTechGuyOnline.com</a>
        </div>

        <div className="footer-col">
          <h4>Support</h4>
          <a href="mailto:info@pctechguyonline.com">Email Us</a>
          <a href="https://wa.me/447933101083" target="_blank" rel="noopener noreferrer">WhatsApp</a>
          <Link to="/">FAQ</Link>
        </div>

        <div className="footer-col">
          <h4>Connect</h4>
          <a href="https://www.facebook.com/pctechguyonline" target="_blank" rel="noopener noreferrer">Facebook</a>
          <a href="https://www.instagram.com/pctechguyonline" target="_blank" rel="noopener noreferrer">Instagram</a>
          <a href="https://x.com/pctechguyonline" target="_blank" rel="noopener noreferrer">X</a>
          <a href="https://www.tiktok.com/@pctechguyonline" target="_blank" rel="noopener noreferrer">TikTok</a>
          <a href="https://www.youtube.com/channel/UCKZVSHOWfJAJKdDr73pmNnA" target="_blank" rel="noopener noreferrer">YouTube</a>
        </div>
      </div>

      <div className="footer-bottom">
        <span>Copyright © {new Date().getFullYear()} PcTechGuyOnline | All Rights Reserved.</span>
        <div>
          <a href="https://www.pctechguyonline.com/privacy-policy" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
          <span style={{ margin: "0 8px", color: "#555" }}>|</span>
          <a href="https://www.pctechguyonline.com/terms" target="_blank" rel="noopener noreferrer">Terms of Service</a>
        </div>
      </div>
    </footer>
  );
}
