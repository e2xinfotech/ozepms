/** Razorpay Checkout (hosted script, loaded only when a payment window is opened). */
export interface RazorpayCheckout { key: string; order_id: string; amount: number; currency: string; name: string; description: string; prefill: Record<string, string>; notes: Record<string, string>; payment_id: string }
export interface RazorpayResult { razorpay_payment_id: string; razorpay_order_id: string; razorpay_signature: string }
export type RazorpayCtor = new (o: Record<string, unknown>) => { open: () => void; on: (ev: string, cb: (r: { error?: { description?: string } }) => void) => void };

export function loadCheckoutScript(): Promise<RazorpayCtor> {
    const w = window as unknown as { Razorpay?: RazorpayCtor };
    if (w.Razorpay) return Promise.resolve(w.Razorpay);
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://checkout.razorpay.com/v1/checkout.js';
        s.async = true;
        s.onload = () => (w.Razorpay ? resolve(w.Razorpay) : reject(new Error('checkout')));
        s.onerror = () => reject(new Error('checkout'));
        document.head.appendChild(s);
    });
}
