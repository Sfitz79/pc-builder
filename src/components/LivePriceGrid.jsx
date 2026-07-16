import { useState, useMemo } from "react";
import { LivePriceCard } from "./LivePriceCard";
import { useLivePrices } from "../hooks/useLivePrices";

export function LivePriceGrid() {
  const { prices, loading } = useLivePrices();
  const [category, setCategory] = useState("all");
  const [maxPrice, setMaxPrice] = useState("");
  const [search, setSearch] = useState("");

  const filtered = useMemo(() => {
    return prices
      .filter(p =>
        category === "all" ? true : p.category?.toLowerCase() === category.toLowerCase()
      )
      .filter(p =>
        maxPrice
          ? p.lowest_price && Number(p.lowest_price) <= parseFloat(maxPrice)
          : true
      )
      .filter(p =>
        search
          ? p.name?.toLowerCase().includes(search.toLowerCase()) ||
            `${p.brand || ""} ${p.model || ""}`.toLowerCase().includes(search.toLowerCase())
          : true
      );
  }, [prices, category, maxPrice, search]);

  if (loading) return <div>Loading prices…</div>;

  return (
    <div className="price-grid">
      <h2>Live UK Component Prices</h2>
      <p className="price-grid__count">{filtered.length} of {prices.length} components</p>

      <div className="price-grid__filters">
        <input
          type="text"
          placeholder="Search CPU / GPU / RAM…"
          value={search}
          onChange={e => setSearch(e.target.value)}
        />
        <select value={category} onChange={e => setCategory(e.target.value)}>
          <option value="all">All categories</option>
          <option value="cpu">CPU</option>
          <option value="gpu">GPU</option>
          <option value="ram">RAM</option>
          <option value="storage">Storage</option>
          <option value="cooler">Cooler</option>
          <option value="motherboard">Motherboard</option>
          <option value="psu">PSU</option>
          <option value="case">Case</option>
        </select>
        <input
          type="number"
          placeholder="Max price (£)"
          value={maxPrice}
          onChange={e => setMaxPrice(e.target.value)}
        />
      </div>

      <div className="price-grid__list">
        {filtered.map(p => (
          <LivePriceCard key={p.id || p.sku || p.name} product={p} />
        ))}
      </div>
    </div>
  );
}
