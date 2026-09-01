document.addEventListener('alpine:init', () => {
    Alpine.store('checkout', {
        selectionKey: 'pctg.checkout.selection',
        orderKey: 'pctg.checkout.order',

        initialized: false,
        selection: null,
        order: null,
        sdkReady: false,
        processing: false,
        creating: false,
        status: 'idle',
        error: null,
        paypalButtonsRendered: false,
        sdkPromise: null,

        get currency() {
            return (this.order && this.order.currency) || 'GBP';
        },

        get lineItems() {
            return (this.order && this.order.line_items) || [];
        },

        get amount() {
            return (this.order && this.order.amounts) || null;
        },

        get partsCount() {
            return this.lineItems.filter(item => item.price > 0).length;
        },

        get buildName() {
            return (this.order && this.order.build && this.order.build.name) || 'Custom Build';
        },

        get hasSelection() {
            return this.selection !== null && this.selection.components.length > 0;
        },

        get hasOrder() {
            return this.order !== null && !!this.order.order_id;
        },

        get totalLabel() {
            return this.amount ? this.money(this.amount.total) : 'Loading…';
        },

        get paid() {
            return this.status === 'paid' || (this.order && this.order.status === 'paid');
        },

        get confirmationUrl() {
            return (this.order && this.order.confirmation_url) || null;
        },

        get paypalConfigured() {
            return !!(this.order && this.order.paypal_client_id);
        },

        money(value) {
            return new Intl.NumberFormat('en-GB', {
                style: 'currency',
                currency: this.currency,
                minimumFractionDigits: 2
            }).format(value || 0);
        },

        init() {
            if (this.initialized) return;
            this.initialized = true;

            if (!window.location.pathname.startsWith('/builder/checkout')) return;

            this.loadSelection();

            const savedOrder = sessionStorage.getItem(this.orderKey);
            if (savedOrder) {
                try {
                    this.order = JSON.parse(savedOrder);
                } catch (e) {
                    this.order = null;
                }
            }

            if (this.hasSelection && !this.hasOrder) {
                this.createOrder();
            } else if (this.hasOrder) {
                this.refreshOrder();
            }
        },

        loadSelection() {
            try {
                const raw = sessionStorage.getItem(this.selectionKey);
                this.selection = raw ? JSON.parse(raw) : null;
            } catch (e) {
                this.selection = null;
            }
        },

        selectedBuildPayload() {
            if (!this.selection) {
                return { components: [] };
            }

            return {
                name: this.buildName,
                purpose: this.selection.purpose || null,
                resolution: this.selection.resolution || null,
                budget: this.selection.budget || null,
                components: this.selection.components.map(({ category, id }) => ({ category, id }))
            };
        },

        csrfToken() {
            const meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.content : '';
        },

        ownerHeaders() {
            const headers = {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken()
            };

            if (this.order && this.order.owner_token) {
                headers['X-Owner-Token'] = this.order.owner_token;
            }

            return headers;
        },

        async createOrder() {
            this.creating = true;
            this.status = 'creating';
            this.error = null;

            try {
                const response = await fetch('/builder/orders', {
                    method: 'POST',
                    headers: this.ownerHeaders(),
                    body: JSON.stringify(this.selectedBuildPayload())
                });

                const data = await response.json();

                if (!response.ok) {
                    this.status = 'idle';
                    this.error = data.message || 'Could not create your order.';
                    return;
                }

                this.order = {
                    order_id: data.order_id,
                    owner_token: data.owner_token,
                    build: {
                        name: data.build_name,
                        resolution: data.resolution,
                        share_slug: data.share_slug,
                        mockup_url: data.mockup_url
                    },
                    line_items: data.line_items,
                    amounts: data.amounts,
                    status: 'draft',
                    currency: data.amounts.currency
                };

                sessionStorage.setItem(this.orderKey, JSON.stringify(this.order));
                this.status = 'ready';
                this.refreshOrder();
            } catch (e) {
                this.status = 'idle';
                this.error = 'Could not reach the server. Please try again.';
            } finally {
                this.creating = false;
            }
        },

        async refreshOrder() {
            if (!this.hasOrder) return;

            this.creating = true;

            try {
                const response = await fetch('/builder/orders/' + this.order.order_id, {
                    headers: this.ownerHeaders()
                });

                if (!response.ok) return;

                const data = await response.json();
                this.order = Object.assign(this.order, data);
                sessionStorage.setItem(this.orderKey, JSON.stringify(this.order));

                if (data.status === 'paid') {
                    this.status = 'paid';
                    return;
                }

                this.status = 'ready';
                this.readyPayPal();
            } catch (e) {
                // Keep the local copy.
            } finally {
                this.creating = false;
            }
        },

        loadSdk(clientId, sandbox) {
            if (this.sdkPromise) return this.sdkPromise;

            const base = sandbox ? 'https://www.sandbox.paypal.com/sdk/js' : 'https://www.paypal.com/sdk/js';
            const url = base + '?client-id=' + encodeURIComponent(clientId) +
                '&intent=capture&currency=' + encodeURIComponent(this.currency) +
                '&commit=true';

            this.sdkPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = url;
                script.async = true;
                script.onload = () => resolve(window.paypal);
                script.onerror = () => reject(new Error('PayPal SDK failed to load.'));
                document.head.appendChild(script);
            });

            return this.sdkPromise;
        },

        readyPayPal() {
            if (!this.hasOrder || !this.order.paypal_client_id) return;

            this.sdkReady = true;

            this.loadSdk(this.order.paypal_client_id, this.order.paypal_mode === 'sandbox')
                .then(() => {
                    this.renderPayPalButtons();
                })
                .catch(() => {
                    this.error = 'PayPal could not be loaded. Please try again.';
                });
        },

        renderPayPalButtons() {
            if (this.paypalButtonsRendered || !window.paypal) return;

            const container = document.getElementById('paypal-button-container');
            if (!container) return;

            window.paypal.Buttons({
                style: { layout: 'vertical', color: 'gold', shape: 'rect', label: 'paypal' },

                createOrder: () => {
                    this.processing = true;
                    this.error = null;

                    return fetch('/builder/orders/' + this.order.order_id + '/paypal', {
                        method: 'POST',
                        headers: this.ownerHeaders()
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (!data.paypal_order_id) {
                                throw new Error(data.message || 'PayPal order could not be created.');
                            }
                            return data.paypal_order_id;
                        });
                },

                onApprove: (data) => {
                    this.status = 'processing';

                    return fetch('/builder/orders/' + this.order.order_id + '/paypal/capture', {
                        method: 'POST',
                        headers: this.ownerHeaders(),
                        body: JSON.stringify({ paypal_order_id: data.orderID })
                    })
                        .then(response => response.json())
                        .then(payload => {
                            if (!payload.confirmed) {
                                throw new Error(payload.message || 'Payment could not be confirmed.');
                            }

                            this.status = 'paid';
                            this.order.status = 'paid';
                            this.order.paid_at = payload.paid_at;
                            this.order.paypal_capture_id = payload.paypal_capture_id;
                            this.order.confirmation_url = payload.confirmation_url;
                            sessionStorage.setItem(this.orderKey, JSON.stringify(this.order));
                            sessionStorage.removeItem(this.selectionKey);

                            window.location.href = payload.confirmation_url;
                        });
                },

                onCancel: () => {
                    this.processing = false;
                    this.status = 'ready';
                    this.error = 'Payment was cancelled. Your build is still saved.';
                },

                onError: () => {
                    this.processing = false;
                    this.status = 'ready';
                    this.error = 'Something went wrong during payment. Please try again.';
                }
            }).render(container).then(() => {
                this.paypalButtonsRendered = true;
            });
        },

        backToBuilder() {
            window.location.href = '/builder';
        },

        resetOrder() {
            sessionStorage.removeItem(this.orderKey);
            this.order = null;
            window.location.reload();
        }
    });
});
