const PAYPAL_API = process.env.PAYPAL_API_URL || "https://api-m.paypal.com";
const CLIENT_ID = process.env.PAYPAL_CLIENT_ID;
const CLIENT_SECRET = process.env.PAYPAL_CLIENT_SECRET;
const MERCHANT_EMAIL = process.env.PAYPAL_MERCHANT_EMAIL || "";

export default async function handler(req, res) {
  if (req.method !== "POST") {
    return res.status(405).json({ error: "Method not allowed" });
  }

  if (!CLIENT_ID || !CLIENT_SECRET) {
    return res.status(500).json({ error: "PayPal API credentials not configured" });
  }

  try {
    const { selections, bundledPrice, paypalOrderId, customerEmail } = req.body;

    if (!selections || !bundledPrice) {
      return res.status(400).json({ error: "Missing required fields" });
    }

    const token = await getAccessToken();
    const items = buildItems(selections);
    const invoice = await createDraftInvoice(token, items, bundledPrice, paypalOrderId, customerEmail);
    await sendInvoice(token, invoice.id);

    res.status(200).json({
      invoiceId: invoice.id,
      invoiceUrl: invoice.detail?.metadata?.recipient_view_url ||
        `https://www.paypal.com/invoicing/invoice/${invoice.id}`,
    });
  } catch (err) {
    console.error("Invoice creation failed:", err);
    res.status(500).json({ error: err.message || "Failed to create invoice" });
  }
}

async function getAccessToken() {
  const body = new URLSearchParams({ grant_type: "client_credentials" });
  const basic = Buffer.from(`${CLIENT_ID}:${CLIENT_SECRET}`).toString("base64");

  const res = await fetch(`${PAYPAL_API}/v1/oauth2/token`, {
    method: "POST",
    headers: {
      Authorization: `Basic ${basic}`,
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: body.toString(),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`PayPal auth failed: ${res.status} ${text}`);
  }

  const data = await res.json();
  return data.access_token;
}

function buildItems(selections) {
  const items = [];
  for (const [cat, item] of Object.entries(selections)) {
    if (!item || !item.name) continue;
    const price = parseFloat(item.price_usd) || parseFloat(item.price) || 0;
    if (price <= 0) continue;
    items.push({
      name: item.name,
      description: cat.replace(/-/g, " "),
      quantity: 1,
      unit_amount: { currency_code: "GBP", value: price.toFixed(2) },
    });
  }
  return items;
}

async function createDraftInvoice(token, items, total, paypalOrderId, customerEmail) {
  const today = new Date().toISOString().split("T")[0];
  const due = new Date(Date.now() + 7 * 86400000).toISOString().split("T")[0];

  const body = {
    detail: {
      invoice_number: `PCTG-${Date.now()}`,
      reference: paypalOrderId || "",
      invoice_date: today,
      currency_code: "GBP",
      note: "Thank you for your PCTG PC Builder order!",
      terms_and_conditions: "Full payment is due within 7 days. All builds include a 2-year warranty.",
      payment_term: { term_type: "NET_7", due_date: due },
      metadata: { created_by: "PCTG PC Builder" },
    },
    invoicer: {
      name: { given_name: "PCTG", surname: "PC Builder" },
      email_address: MERCHANT_EMAIL,
    },
    primary_recipients: [
      {
        billing_info: {
          email_address: customerEmail || "",
        },
      },
    ],
    items,
    configuration: {
      partial_payment: { allow_partial_payment: false },
      allow_tip: false,
      tax_calculated_after_discount: false,
      tax_inclusive: false,
      template_id: "",
    },
    amount: {
      currency_code: "GBP",
      value: total.toFixed(2),
      breakdown: {
        item_total: { currency_code: "GBP", value: items.reduce((s, i) => s + parseFloat(i.unit_amount.value), 0).toFixed(2) },
        shipping: { currency_code: "GBP", value: "0.00" },
        tax_total: { currency_code: "GBP", value: "0.00" },
        discount: { currency_code: "GBP", value: "0.00" },
      },
    },
  };

  const res = await fetch(`${PAYPAL_API}/v2/invoicing/invoices`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`PayPal create invoice failed: ${res.status} ${text}`);
  }

  return res.json();
}

async function sendInvoice(token, invoiceId) {
  const res = await fetch(`${PAYPAL_API}/v2/invoicing/invoices/${invoiceId}/send`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      send_to_invoicer: true,
      send_to_recipient: true,
    }),
  });

  if (!res.ok) {
    const text = await res.text();
    console.error(`PayPal send invoice warning: ${res.status} ${text}`);
  }
}
