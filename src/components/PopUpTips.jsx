import React, { useState, useEffect } from 'react';
import './PopUpTips.css';
import { usePCStore } from '../store/usePCStore';

const TIPS_DEFINITION = [
  {
    id: 'cpu-gpu-balance',
    condition: (selections) => selections.gpu && selections.cpu && (
      (selections.gpu.price > 800 && selections.cpu.price < 200) ||
      (selections.cpu.price > 500 && selections.gpu.price < 300)
    ),
    text: "Consider balancing your CPU and GPU better to avoid performance bottlenecks!",
    icon: '⚖️'
  },
  {
    id: 'ram-dual-channel',
    condition: (selections) => selections.ram && selections.ram.modules < 2,
    text: "Dual-channel RAM (2 sticks) is much faster than a single stick for modern CPUs!",
    icon: '⚡'
  },
  {
    id: 'ssd-boot',
    condition: (selections) => selections.storage && selections.storage.type?.toLowerCase().includes('hdd'),
    text: "Using an SSD as your primary boot drive is highly recommended for a fast system!",
    icon: '🚀'
  },
  {
    id: 'psu-overhead',
    condition: (selections) => selections.psu && selections.gpu && (
      parseFloat(selections.psu.wattage) < 600 && selections.gpu.name?.includes('3080')
    ),
    text: "Your selected PSU might be cutting it close for that powerful GPU. Consider more wattage!",
    icon: '🔌'
  }
];

export default function PopUpTips() {
  const [activeTips, setActiveTips] = useState([]);
  const [dismissedTips, setDismissedTips] = useState(new Set());
  const selections = usePCStore(state => state.selections);

  useEffect(() => {
    const newTips = TIPS_DEFINITION.filter(tip => 
      !dismissedTips.has(tip.id) && 
      !activeTips.some(at => at.id === tip.id) &&
      tip.condition(selections)
    );

    if (newTips.length > 0) {
      setActiveTips(prev => [...prev, ...newTips]);
      
      // Auto-dismiss after 8 seconds
      newTips.forEach(tip => {
        setTimeout(() => {
          handleDismiss(tip.id);
        }, 8000);
      });
    }
  }, [selections, dismissedTips, activeTips]);

  const handleDismiss = (id) => {
    setActiveTips(prev => prev.filter(tip => tip.id !== id));
    setDismissedTips(prev => new Set(prev).add(id));
  };

  if (activeTips.length === 0) return null;

  return (
    <div className="tips-container">
      {activeTips.map(tip => (
        <div key={tip.id} className="tip-popup">
          <button className="tip-close" onClick={() => handleDismiss(tip.id)}>×</button>
          <div className="tip-icon">{tip.icon}</div>
          <div className="tip-content">{tip.text}</div>
        </div>
      ))}
    </div>
  );
}
