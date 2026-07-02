import { useState } from "react";

export default function SaveBuildModal({ onClose, onSave }) {
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");

  const handleSubmit = (e) => {
    e.preventDefault();
    if (onSave) onSave({ name, description });
  };

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-box" onClick={e => e.stopPropagation()}>
        <h2>Save Build</h2>
        <form onSubmit={handleSubmit}>
          <div className="field">
            <label>Build Name</label>
            <input type="text" value={name} onChange={e => setName(e.target.value)} placeholder="My Awesome Build" required />
          </div>
          <div className="field">
            <label>Description</label>
            <textarea style={{ width: "100%", minHeight: "80px", background: "#1a1a2e", color: "#e6e6e6", border: "1px solid #333", borderRadius: "6px", padding: "10px" }} value={description} onChange={e => setDescription(e.target.value)} placeholder="Describe your build..." />
          </div>
          <div className="actions">
            <button type="submit">Save changes</button>
            <button type="button" className="secondary" onClick={onClose}>Cancel</button>
          </div>
        </form>
      </div>
    </div>
  );
}
