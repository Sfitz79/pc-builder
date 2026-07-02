import React, { useState, useEffect, useRef } from 'react';
import './ChatBot.css';
import { usePCStore } from '../store/usePCStore';
import { askGemini } from '../utils/geminiChat';
import { checkCompatibility } from '../utils/compatibility';
const pctgAvatarImg = '/pctgavatar.png';

const GAME_REQUIREMENTS = {
  "cyberpunk 2077": { name: "Cyberpunk 2077 + Phantom Liberty", min: "i7-6700 / Ryzen 5 1600 + GTX 1060 6GB + 12GB RAM", rec: "i7-12700 / Ryzen 7 7800X3D + RTX 2060 Super + 16GB RAM", ultra: "i9-12900 / Ryzen 9 7900X + RTX 3080 + 20GB RAM" },
  "battlefield 6": { name: "Battlefield 6", min: "i5-8400 / Ryzen 5 2600 + RTX 2060 + 16GB RAM", rec: "i7-10700 / Ryzen 7 3700X + RTX 3060 Ti + 16GB RAM" },
  "black myth wukong": { name: "Black Myth: Wukong", min: "i5-8400 / Ryzen 5 1600 + GTX 1060 + 16GB RAM", rec: "i7-9700 / Ryzen 5 5500 + RTX 2060 + 16GB RAM" },
  "black ops 6": { name: "COD: Black Ops 6", min: "i5-6600 / Ryzen 5 1400 + GTX 960 + 8GB RAM", rec: "i7-6700K / Ryzen 5 1600X + RTX 3060 + 12GB RAM" },
  "starfield": { name: "Starfield", min: "i5-8400 + RTX 1070 Ti + 16GB RAM" },
  "alan wake 2": { name: "Alan Wake II", min: "i5-7600K + RTX 2060 Super + 16GB RAM" }
};

const KNOWLEDGE_BASE = [
  { keywords: ['hi', 'hello', 'hey', 'greetings', 'sup', 'yo'],
    response: "Hey there! Welcome to PCTG. Looking to build a custom PC? Tell me your budget and what you'll use it for and I'll recommend the perfect components!" },
  { keywords: ['amd', 'ryzen', 'cpu', 'processor', 'intel', 'core', 'arrow lake', 'zen 5', '9000'],
    response: "AMD's Ryzen 9000 series dominates gaming in 2026. The **Ryzen 7 9800X3D** is the best gaming CPU at $460 — 30-38% faster than Intel in games. The **Ryzen 7 9700X** at $309 is the best value (90-95% of X3D perf at 1440p). Intel's Core Ultra 7 265K is decent for productivity. What's your main use?" },
  { keywords: ['gpu', 'graphics', 'nvidia', 'rtx', '5090', '5080', '5070', 'radeon', 'rx 9070', '9070 xt', 'blackwell'],
    response: "**RTX 5090** (32GB) is the fastest GPU — 169 FPS at 4K but $1999+. **RTX 5080** (16GB) is excellent for 4K at $999+. **RTX 5070** (12GB) at $550 is best value NVIDIA. **RX 9070 XT** (16GB) at $600 is best AMD value. For 1440p, RTX 5070 or RX 9070 are sweet spots. What's your target resolution and budget?" },
  { keywords: ['ram', 'memory', 'ddr4', 'ddr5', 'dual channel', 'cl14', 'cl30', '6000', '8000'],
    response: "DDR5 is standard now. **32GB (2x16GB) DDR5-6000 CL30** is the sweet spot for gaming. For heavy productivity/streaming, 64GB is worth it. What kind of work will you be doing?" },
  { keywords: ['psu', 'power supply', 'wattage', '750w', '850w', '1000w', '80+ gold', 'tier', 'cable'],
    response: "For mid-range builds (RTX 5070/RX 9070), **750W 80+ Gold** is sufficient. For RTX 5080/5090, **850-1000W** recommended. Always get a quality unit from a known brand. What GPU are you pairing?" },
  { keywords: ['ssd', 'storage', 'nvme', 'm.2', 'pcie 5', 'pcie 4', 'gen4', 'gen5', 'sata'],
    response: "**Gen4 NVMe** (7GB/s) is plenty for gaming — excellent load times. **Gen5** is faster (10+ GB/s) but premium-priced. 1TB is a good minimum, 2TB recommended for modern games that can be 100-200GB each. How much storage do you need?" },
  { keywords: ['motherboard', 'mobo', 'chipset', 'b850', 'x870', 'z890', 'b760', 'wifi', 'rgb'],
    response: "For AMD, **B850** is the sweet spot (supports PCIe 5.0). **X870** for more USB/expandability. For Intel Core Ultra, **Z890** is the go-to. Make sure socket matches: **AM5 for Ryzen 9000**, **LGA1851 for Intel Core Ultra**. Need WiFi?" },
  { keywords: ['cooler', 'aio', 'liquid', 'cooling', 'thermal', '280', '360', 'kraken', 'nzxt'],
    response: "For most CPUs (Ryzen 5/7, Core i5/7), a quality **air cooler** is sufficient. For Ryzen 9/i9 or overclocking, a **280-360mm AIO liquid cooler** is recommended to manage heat. PCTG handles installation — what CPU are you pairing?" },
  { keywords: ['case', 'airflow', 'mesh', ' tempered glass', 'atx', 'itx', 'matx', 'gpu clearance'],
    response: "Make sure the case fits your motherboard (ATX/mATX/ITX) and has enough GPU clearance and cooler height support. Most mid-tower ATX cases fit everything. Do you have a preferred style or size?" },
  { keywords: ['bottleneck', 'bottlenecking', 'compatibility', 'compatible', 'fit'],
    response: "Bottleneck depends on resolution. At **4K**, GPU does most work — CPU matters less. At **1080p**, CPU matters more. A **balanced build** is best: don't pair a top GPU with a budget CPU or vice versa. What resolution are you targeting?" },
  { keywords: ['budget', 'cheap', 'affordable', 'money', 'price', 'value', 'cost', 'cheapest', 'low cost'],
    response: "**£800-1,200** is the sweet spot: Ryzen 7 9700X + RTX 5070 + 32GB DDR5 — great 1440p gaming. **£1,500-2,000** gets you RTX 5070 Ti or RX 9070 XT for entry 4K. **£500-700** gets you a capable 1080p rig. What's your target budget?" },
  { keywords: ['streaming', 'twitch', 'obs', 'recording', 'editing', 'creator', 'render', 'production'],
    response: "For streaming, prioritize **8+ CPU cores** (Ryzen 7 9700X/9800X3D), **32GB RAM**, and an **NVIDIA GPU** (excellent NVENC encoder).\n\n**Gaming/Streaming 4K Subsection:**\n1. **Entry Tier** (£1,050-£1,200): RTX 5060 Ti (16GB), Ryzen 5 7600X, 32GB RAM.\n2. **Mid Tier** (£1,450-£1,650): RX 9070 XT, Ryzen 7 7700X, 32GB RAM.\n3. **Competitive Tier** (£2,300-£2,600): RTX 5080, Ryzen 7 9800X3D, 32GB RAM.\n4. **Ultimate Tier** (£4,200-£4,600): RTX 5090, Ryzen 9 9950X, 64GB RAM." },
  { keywords: ['esports', 'comp', 'fps', 'valorant', 'csgo', 'league', 'overwatch', 'low latency'],
    response: "For competitive FPS, a **high-refresh monitor** and **fast CPU** matter most. A **Ryzen 7 9800X3D** or **9700X** with a **mid-range GPU** (RTX 5070/Arc B580) will push 200+ FPS in Valorant/CS2. What's your target FPS and monitor refresh rate?" },
  { keywords: ['4k', '1440p', '1080p', 'resolution', 'monitor', 'hz', 'refresh rate'],
    response: "**1080p**: RTX 4060/Arc B580 class. **1440p** (the sweet spot): RTX 5070/RX 9070 class.\n\n**4K Gaming PC Subsection:**\n1. **Entry 4K** (£1,450-£1,600): RTX 5070, Ryzen 5 9600X.\n2. **Ultimate 4K** (£4,500-£5,200): RTX 5090, Ultra 9 285K.\n\n**Gaming/Streaming 4K Subsection:**\n1. **Entry Tier** (£1,050-£1,200): RTX 5060 Ti, Ryzen 5 7600X.\n2. **Mid Tier** (£1,450-£1,650): RX 9070 XT, Ryzen 7 7700X.\n3. **Competitive Tier** (£2,300-£2,600): RTX 5080, Ryzen 7 9800X3D.\n4. **Ultimate Tier** (£4,200-£4,600): RTX 5090, Ryzen 9 9950X." },
  { keywords: ['cyberpunk', 'phantom liberty'],
    response: "**Cyberpunk 2077** min: i7-6700/R5-1600 + GTX 1060 6GB + 12GB. Rec for 60fps@1080p High: i7-12700/R7-7800X3D + RTX 2060S + 16GB. Ultra 4K 60fps: i9-12900/R9-7900X + RTX 3080 + 20GB. Ray tracing Overdrive needs RTX 4080. What settings are you aiming for?" },
  { keywords: ['battlefield 6', 'battlefield'],
    response: "**Battlefield 6** min: i5-8400/R5-2600 + RTX 2060 + 16GB. Rec for 60fps@1440p High: i7-10700/R7-3700X + RTX 3060 Ti + 16GB. Needs Windows 11 with TPM 2.0 enabled. 80GB SSD recommended." },
  { keywords: ['black myth', 'wukong'],
    response: "**Black Myth: Wukong** min: i5-8400/R5-1600 + GTX 1060 + 16GB. Rec: i7-9700/R5-5500 + RTX 2060 + 16GB. Large install at 130GB SSD. At cinematic settings with RT, an RTX 4070 does ~58fps@1440p." },
  { keywords: ['call of duty', 'cod', 'black ops 6', 'warzone'],
    response: "**COD: Black Ops 6** min: i5-6600/R5-1400 + GTX 960 + 8GB. Rec 60fps: i7-6700K/R5-1600X + RTX 3060 + 12GB. 4K competitive: i7-8700K/R7-2700X + RTX 3080/4070 + 16GB. 102GB SSD required." },
  { keywords: ['windows', 'win 11', 'win10', 'operating system', 'os'],
    response: "**Windows 11** requires: TPM 2.0 (must be enabled in BIOS), UEFI with Secure Boot, 4GB RAM, 64GB storage, DirectX 12 GPU. Intel 8th Gen+ or AMD Ryzen 2000+ supported. **Windows 10 support ended October 2025** — no more security updates. We include Windows 11 Pro with all PCTG builds." },
  { keywords: ['upgrade', 'upgrading', 'new pc', 'from scratch', 'first time'],
    response: "Starting from scratch? Perfect! Tell me your budget and what you want to do with your PC and I'll recommend a complete build. PCTG handles all the assembly. A **£1,000-1,200** build gets you excellent 1440p gaming with a **Ryzen 7 9700X + RTX 5070**." },
  { keywords: ['rgb', 'argb', 'lighting', 'glow', 'sync'],
    response: "RGB adds style! Most components offer RGB variants. Do you want full RGB lighting, a clean minimal look, or something in between? PCTG can build it either way." },
  { keywords: ['warranty', 'support', 'help', 'problems', 'issue', 'contact', 'email'],
    response: "PCTG offers a **2-year warranty** and **free lifetime tech support** on all custom builds. Email info@pctechguyonline.com or call +44 7933 101083. They'll sort any issues quickly!" }
];

const PERSONA = {
  name: "PCTG Builder",
  tagline: "Custom PC experts — you choose the parts, we build it!",
  prompts: [
    "What's your budget and what will you use your PC for?",
    "Gaming, streaming, productivity — what's your main use case?",
    "What resolution do you want to game at — 1080p, 1440p, or 4K?",
    "Do you have a preference between AMD or Intel for the CPU?",
    "What's more important — raw performance or value for money?"
  ]
};

export default function ChatBot() {
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState([
    { text: "Hey! 👋 I'm your PCTG product advisor. Tell me your budget and what you want to do with your PC and I'll help you pick the perfect components — PCTG handles the build!", sender: 'bot' }
  ]);
  const [inputValue, setInputValue] = useState('');
  const [isTyping, setIsTyping] = useState(false);
  const [ideaText, setIdeaText] = useState(PERSONA.prompts[0]);
  const messagesEndRef = useRef(null);
  const selections = usePCStore(state => state.selections);

  useEffect(() => {
    const interval = setInterval(() => {
      setIdeaText(PERSONA.prompts[Math.floor(Math.random() * PERSONA.prompts.length)]);
    }, 5000);
    return () => clearInterval(interval);
  }, []);

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages, isTyping]);

  const generateFallbackResponse = (input) => {
    for (const [gameName, specs] of Object.entries(GAME_REQUIREMENTS)) {
      if (input.includes(gameName)) {
        let response = `**${specs.name}** requirements:\n`;
        if (specs.min) response += `• Minimum: ${specs.min}\n`;
        if (specs.rec) response += `• Recommended: ${specs.rec}\n`;
        if (specs.ultra) response += `• Ultra 4K: ${specs.ultra}\n`;
        response += "\nWhat's your target settings and budget? I can recommend parts that match these requirements!";
        return response;
      }
    }
    for (const item of KNOWLEDGE_BASE) {
      if (item.keywords.some(keyword => input.includes(keyword))) {
        return item.response;
      }
    }
    if (input.includes('build') || input.includes('my pc') || input.includes('this pc')) {
      const componentCount = Object.keys(selections).length;
      if (componentCount === 0) {
        return "You haven't picked any parts yet! Tell me your budget and what you want to use your PC for, and I'll recommend the best components.";
      }
      if (componentCount < 5) {
        return `You've selected ${componentCount} part(s) so far. What's your budget? I can help fill in the remaining components.`;
      }
      const hasGPU = selections['gpu'];
      const hasCPU = selections['cpu'];
      if (!hasGPU) return "You've got a CPU picked but no GPU yet. At £800-1,200 a **Ryzen 7 9700X + RTX 5070** is the sweet spot for 1440p. What's your GPU budget?";
      if (!hasCPU) return "You've got a GPU picked but no CPU yet. For gaming, **Ryzen 7 9800X3D** is best at $460. **Ryzen 7 9700X** at $309 is the best value. Do you prefer AMD or Intel?";
      return `You have ${componentCount} components selected. Would you like me to review the build and suggest any improvements to balance performance?`;
    }
    if (input.includes('help') || input.includes('recommend') || input.includes('suggest')) {
      return "Tell me your budget and what you'll use the PC for. For example: \"I want a £1500 PC for 1440p gaming\" or \"I need a PC for Cyberpunk 2077 at 4K with ray tracing\". The more detail the better!";
    }
    if (input.includes('compatible') || input.includes('compatibility') || input.includes('work together') || input.includes('fit')) {
      const issues = checkCompatibility(selections);
      if (issues.length === 0) return "Everything looks compatible! Great component choices. Key things to verify: CPU socket matches motherboard, DDR5 gen matches, PSU has enough wattage for your GPU, and case fits everything.";
      return `I spotted ${issues.length} potential compatibility issue(s):\n${issues.map(i => `• ${i}`).join('\n')}`;
    }
    if (input.includes('price') || input.includes('total') || input.includes('cost')) {
      const total = Object.values(selections).reduce((sum, item) => sum + (parseFloat(item?.price) || 0), 0);
      return `Your selected parts total approximately **£${total.toLocaleString('en-GB')}**. Want to reduce costs? Tell me your target budget and I'll suggest smart alternatives — like swapping to a **Ryzen 7 9700X instead of 9800X3D** (saves £100+ with minimal gaming perf loss at 1440p).`;
    }
    if (input.includes('1440p')) {
      return "**1440p** is the sweet spot in 2026! For great 1440p gaming: **£800-1,200**: Ryzen 7 9700X + RTX 5070 + 32GB DDR5. For higher refresh 1440p: **£1,500-2,000**: Ryzen 7 9800X3D + RTX 5070 Ti + 32GB DDR5. What's your budget?";
    }
    if (input.includes('1080p')) {
      return "**1080p** gaming is very achievable on a budget. **£500-700**: Ryzen 5 5600/7600 + Intel Arc B580/RTX 4060 + 16GB. This handles esports at 200+ FPS and AAA games at medium-high settings. Want to go higher or stick to a strict budget?";
    }
    if (input.includes('4k') || input.includes('4K')) {
      return "**4K** gaming demands powerful hardware: **RTX 5080** (16GB, $999+) is the minimum for solid 4K performance. **RTX 5090** (32GB, $1999+) for high-refresh 4K. Pair with a **Ryzen 7 9800X3D** or **Ryzen 9 9950X3D**. What's your 4K target — 60fps or higher?";
    }
    return "I'm here to help you choose the right components! What's your budget, what games/apps will you use, and what resolution are you targeting? For example: \"£1500 for 1440p gaming\" or \"£2500 for Cyberpunk 2077 at 4K\".";
  };

  const handleSend = async () => {
    if (!inputValue.trim()) return;
    const userMessage = { text: inputValue, sender: 'user' };
    setMessages(prev => [...prev, userMessage]);
    setInputValue('');
    setIsTyping(true);

    try {
      const history = messages.slice(-10);
      const aiResponse = await askGemini(inputValue, selections, history);
      if (aiResponse) {
        setMessages(prev => [...prev, { text: aiResponse, sender: 'bot' }]);
      } else {
        const fallback = generateFallbackResponse(inputValue.toLowerCase());
        setTimeout(() => {
          setMessages(prev => [...prev, { text: fallback, sender: 'bot' }]);
        }, 600);
      }
    } catch {
      const fallback = generateFallbackResponse(inputValue.toLowerCase());
      setTimeout(() => {
        setMessages(prev => [...prev, { text: fallback, sender: 'bot' }]);
      }, 600);
    } finally {
      setTimeout(() => setIsTyping(false), 400);
    }
  };

  const handleKeyPress = (e) => {
    if (e.key === 'Enter') { handleSend(); }
  };

  return (
    <div className="chatbot-container">
      {!isOpen && (
        <div className="chatbot-idea-bubble">
          <span className="chatbot-idea-text">{ideaText}</span>
        </div>
      )}
      <button className="chatbot-button" onClick={() => setIsOpen(!isOpen)}>
        <img src={pctgAvatarImg} alt="PCTG" className="chatbot-avatar" />
        {isOpen ? '💻' : '💬'} PC Helper
      </button>
      {isOpen && (
        <div className="chatbot-window">
          <div className="chatbot-header">
            <h3>🖥️ {PERSONA.name}</h3>
            <span style={{fontSize: '10px', opacity: 0.7}}>{PERSONA.tagline}</span>
            <button className="chatbot-close" onClick={() => setIsOpen(false)}>×</button>
          </div>
          <div className="chatbot-messages">
            {messages.map((msg, idx) => (
              <div key={idx} className={`message ${msg.sender}`}>{msg.text}</div>
            ))}
            {isTyping && <div className="typing-indicator">Advisor is thinking...</div>}
            <div ref={messagesEndRef} />
          </div>
          <div className="chatbot-input">
            <input type="text" value={inputValue} onChange={(e) => setInputValue(e.target.value)} onKeyPress={handleKeyPress} placeholder="Budget? Use case? Resolution?" />
            <button onClick={handleSend}>Send</button>
          </div>
        </div>
      )}
    </div>
  );
}
