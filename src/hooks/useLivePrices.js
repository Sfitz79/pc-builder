import { useEffect, useState } from "react";

export function useLivePrices() {
  const [prices, setPrices] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function load() {
      try {
        const supabaseUrl = import.meta.env.VITE_SUPABASE_URL;
        const anonKey = import.meta.env.VITE_SUPABASE_ANON_KEY;

        if (supabaseUrl && anonKey) {
          const res = await fetch(
            `${supabaseUrl}/rest/v1/components_prices?select=*`,
            {
              headers: {
                apikey: anonKey,
                Authorization: `Bearer ${anonKey}`,
              },
            }
          );
          if (res.ok) {
            const data = await res.json();
            setPrices(data);
            setLoading(false);
            return;
          }
        }

        const localRes = await fetch("/prices.json");
        if (localRes.ok) {
          const data = await localRes.json();
          setPrices(data);
        }
      } catch {
        try {
          const localRes = await fetch("/prices.json");
          if (localRes.ok) {
            const data = await localRes.json();
            setPrices(data);
          }
        } catch {}
      }
      setLoading(false);
    }

    load();
  }, []);

  return { prices, loading };
}
