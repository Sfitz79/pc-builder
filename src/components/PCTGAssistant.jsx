import React, { useState, useEffect, useRef } from 'react';
import { usePCStore } from '../store/usePCStore';
import { askGemini, generateDynamicTip } from '../utils/geminiChat';
import './PCTGAssistant.css';

export default function PCTGAssistant() {
  const selections = usePCStore((state) => state.selections);
  const [currentTip, setCurrentTip] = useState('What will you use your PC for? Tell me your budget and I\'ll recommend the perfect components!');
  const [chatMessages, setChatMessages] = useState([
    { text: "Hey! I'm PCTG, your AI product advisor. Tell me your budget and what you'll use your PC for — I'll help you pick the right components!", sender: 'bot' }
  ]);
  const [chatInput, setChatInput] = useState('');
  const [isChatOpen, setIsChatOpen] = useState(false);
  const [isThinking, setIsThinking] = useState(false);

  useEffect(() => {
    const updateTip = async () => {
      const tip = await generateDynamicTip(selections);
      setCurrentTip(tip);
    };
    updateTip();
    const interval = setInterval(updateTip, 12000);
    return () => clearInterval(interval);
  }, [selections]);

  const handleChatSubmit = async (e) => {
    e.preventDefault();
    if (!chatInput.trim() || isThinking) return;
    const userMsg = { text: chatInput, sender: 'user' };
    setChatMessages(prev => [...prev, userMsg]);
    const input = chatInput;
    setChatInput('');
    setIsThinking(true);

    try {
      const history = chatMessages.slice(-8);
      const response = await askGemini(input, selections, history);
      if (response) {
        setChatMessages(prev => [...prev, { text: response, sender: 'bot' }]);
      } else {
        const lower = input.toLowerCase();
        let fallback = "Tell me your budget and what you'll use your PC for — I'll recommend the best components!";
        if (lower.includes('budget')) fallback = "**£800-1,200** is the sweet spot: Ryzen 7 9700X + RTX 5070 + 32GB DDR5 for great 1440p. **£500-700** gets a capable 1080p rig. What's your target?";
        else if (lower.includes('streaming')) fallback = "For streaming, recommend **8+ CPU cores** (Ryzen 7 9700X), **32GB RAM**, and **NVIDIA GPU** (best NVENC encoder).\n\n**Gaming/Streaming 4K Subsection:**\n1. **Entry Tier**: (£1,050-£1,200)\n2. **Mid Tier**: (£1,450-£1,650)\n3. **Competitive Tier**: (£2,300-£2,600)\n4. **Ultimate Tier**: (£4,200-£4,600)";
        else if (lower.includes('1440p')) fallback = "**1440p** sweet spot: RTX 5070/RX 9070 + Ryzen 7 9700X + 32GB DDR5 — roughly **£1,000-1,500** total. What's your budget?";
        else if (lower.includes('4k')) fallback = "**4K Gaming PC Subsection:**\n1. **Entry 4K**: RTX 5070 (£1,450-£1,600)\n2. **Ultimate 4K**: RTX 5090 (£4,500-£5,200)\n\n**Gaming/Streaming 4K Subsection:**\n1. **Entry Tier**: £1,050-£1,200\n2. **Mid Tier**: £1,450-£1,650\n3. **Competitive Tier**: £2,300-£2,600\n4. **Ultimate Tier**: £4,200-£4,600";
        else if (lower.includes('1080p')) fallback = "**1080p** is very budget-friendly: Ryzen 5 5600/7600 + Arc B580/RTX 4060 + 16GB — roughly **£500-800**. Handles esports at 200+ FPS. What's your budget?";
        else if (lower.includes('compatible') || lower.includes('work')) fallback = "Key checks: **socket** (AM5 for Ryzen, LGA1851 for Intel), **DDR5 gen**, **PSU wattage** for GPU, **case clearance**. Want me to review your selections?";
        else if (lower.includes('cyberpunk')) fallback = "**Cyberpunk 2077** rec for 60fps@1080p: i7-12700/R7-7800X3D + RTX 2060S + 16GB. For 4K Ultra: i9-12900/R9-7900X + RTX 3080 + 20GB. What settings are you targeting?";
        else if (lower.includes('battlefield')) fallback = "**Battlefield 6** rec: i7-10700/R7-3700X + RTX 3060 Ti + 16GB for 1440p High. Needs Win 11 with TPM 2.0. What's your budget?";
        else if (lower.includes('wukong') || lower.includes('black myth')) fallback = "**Black Myth: Wukong** rec: i7-9700/R5-5500 + RTX 2060 + 16GB. Large 130GB install. RTX 4070 does ~58fps@1440p cinematic. Need help with a build for this?";
        else if (lower.includes('cod') || lower.includes('call of duty') || lower.includes('black ops')) fallback = "**COD: Black Ops 6** rec: i7-6700K/R5-1600X + RTX 3060 + 12GB. 4K: RTX 3080/4070. 102GB SSD required. What's your target FPS?";
        else if (lower.includes('windows') || lower.includes('win 11') || lower.includes('os')) fallback = "**Windows 11** requires TPM 2.0, UEFI+Secure Boot, DirectX 12 GPU. Win 10 support ended Oct 2025. PCTG includes **Windows 11 Pro** with every build.";
        else if (lower.includes('build') || lower.includes('pc')) {
          const count = Object.keys(selections).length;
          if (count === 0) fallback = "You haven't picked any parts yet! Tell me your budget and what you'll use your PC for, and I'll recommend the perfect components.";
          else fallback = `You've selected ${count} part(s). What's your budget? I can help fill in the remaining components.`;
        }
        setChatMessages(prev => [...prev, { text: fallback, sender: 'bot' }]);
      }
    } catch {
      setChatMessages(prev => [...prev, { text: "My AI is temporarily offline, but I can still help! Try asking about specific parts or budgets.", sender: 'bot' }]);
    }
    setIsThinking(false);
  };

  return (
    <div className="pctg-assistant">
      <div className="pctg-assistant-inner">

        <div className="pctg-content">
          <div className="bubble">
            <span className="bubble-text">{currentTip}</span>
          </div>
          <div className="pctg-status">
            <span className="pctg-status-label">Build Progress</span>
            <span className="pctg-status-count">{Object.keys(selections).length}/9 parts</span>
            <div className="progress-bar">
              <div className="progress-fill" style={{ width: `${(Object.keys(selections).length / 9) * 100}%` }} />
            </div>
          </div>
          <div className="pctg-chat-mini">
            <div className="pctg-chat-header" onClick={() => setIsChatOpen(!isChatOpen)}>
              <span>Chat with PCTG</span>
              <span>{isChatOpen ? '−' : '+'}</span>
            </div>
            {isChatOpen && (
              <div className="pctg-chat-window">
                <div className="pctg-chat-messages">
                  {chatMessages.map((msg, idx) => (
                    <div key={idx} className={`pctg-chat-msg ${msg.sender}`}>
                      {msg.text.split('\n').map((line, i) => <div key={i}>{line}</div>)}
                    </div>
                  ))}
                  {isThinking && <div className="pctg-chat-typing">Thinking...</div>}
                </div>
                <form className="pctg-chat-form" onSubmit={handleChatSubmit}>
                  <input type="text" value={chatInput} onChange={(e) => setChatInput(e.target.value)} placeholder="Budget? Use case?" disabled={isThinking} />
                  <button type="submit" disabled={isThinking}>Send</button>
                </form>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
