import { useState } from "react";
import { PayPalScriptProvider, PayPalButtons } from "@paypal/react-paypal-js";
import { usePCStore } from "../store/usePCStore";

export default function PayPalCheckout({ amount, onSuccess, onError }) {
  const [invoiceUrl, setInvoiceUrl] = useState(null);
  const [invoiceStatus, setInvoiceStatus] = useState("idle");
  const selections = usePCStore(s => s.selections);
  const bundledPrice = usePCStore(s => s.getBundledPrice);
  const clientId = import.meta.env.VITE_PAYPAL_CLIENT_ID || "";

  if (!clientId) {
    return null;
  }

  const total = amount ?? bundledPrice();
  const itemCount = Object.keys(selections).reduce((n, k) => {
    const v = selections[k];
    if (!v) return n;
    if (Array.isArray(v)) return n + v.filter(x => x && x.name).length;
    return v.name ? n + 1 : n;
  }, 0);

  async function createInvoice(paypalOrderId) {
    setInvoiceStatus("generating");
    try {
      const flatSelections = {};
      for (const [k, v] of Object.entries(selections)) {
        if (!v) continue;
        if (Array.isArray(v)) {
          flatSelections[k] = v.filter(x => x && x.name);
        } else if (v.name) {
          flatSelections[k] = v;
        }
      }
      const res = await fetch("/api/create-invoice", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          selections: flatSelections,
          bundledPrice: total,
          paypalOrderId,
        }),
      });
      const data = await res.json();
      if (res.ok && data.invoiceUrl) {
        setInvoiceUrl(data.invoiceUrl);
        setInvoiceStatus("done");
      } else {
        setInvoiceStatus("error");
      }
    } catch {
      setInvoiceStatus("error");
    }
  }

  if (invoiceUrl) {
    return (
      <div style={{
        padding: "14px", borderRadius: "8px", background: "rgba(0,255,170,0.08)",
        border: "1px solid rgba(0,255,170,0.3)", textAlign: "center", fontFamily: "inherit"
      }}>
        <div style={{ color: "#4ade80", fontSize: "13px", fontWeight: 600, marginBottom: "10px" }}>
          ✓ Payment Successful!
        </div>
        <a href={invoiceUrl} target="_blank" rel="noopener noreferrer"
          style={{
            display: "inline-block", padding: "10px 20px", borderRadius: "6px",
            background: "linear-gradient(135deg, #0070ba, #003087)", color: "#fff",
            textDecoration: "none", fontSize: "13px", fontWeight: 600, fontFamily: "inherit"
          }}>
          📄 View Invoice
        </a>
      </div>
    );
  }

  return (
    <PayPalScriptProvider options={{ clientId, currency: "GBP" }}>
      {invoiceStatus === "generating" && (
        <div style={{
          padding: "14px", borderRadius: "8px", background: "rgba(0,234,255,0.06)",
          border: "1px solid rgba(0,234,255,0.2)", textAlign: "center",
          fontSize: "13px", color: "#00eaff", fontFamily: "inherit"
        }}>
          Generating your invoice...
        </div>
      )}
      {invoiceStatus === "error" && (
        <div style={{
          padding: "10px", borderRadius: "6px", background: "rgba(255,0,94,0.08)",
          border: "1px solid rgba(255,0,94,0.2)", textAlign: "center",
          fontSize: "11px", color: "#ef4444", marginBottom: "8px", fontFamily: "inherit"
        }}>
          Invoice generation failed — you can download the PDF above instead.
        </div>
      )}
      <PayPalButtons
        style={{ layout: "vertical", shape: "rect", color: "gold", label: "checkout", tagline: false }}
        disabled={invoiceStatus === "generating"}
        createOrder={(data, actions) =>
          actions.order.create({
            purchase_units: [{
              description: `PCTG PC Build — ${itemCount} components`,
              amount: { value: total.toFixed(2), currency_code: "GBP" }
            }]
          })
        }
        onApprove={(data, actions) =>
          actions.order.capture().then(details => {
            const order = {
              id: details.id,
              status: details.status,
              amount: total,
              timestamp: new Date().toISOString(),
              selections: JSON.parse(JSON.stringify(selections))
            };
            try {
              const history = JSON.parse(localStorage.getItem("pctg-paypal-orders") || "[]");
              history.unshift(order);
              localStorage.setItem("pctg-paypal-orders", JSON.stringify(history.slice(0, 50)));
            } catch {}
            createInvoice(details.id);
            onSuccess?.(details);
          })
        }
        onCancel={() => onError?.("cancelled")}
        onError={err => onError?.(err.message || "payment_error")}
      />
    </PayPalScriptProvider>
  );
}
