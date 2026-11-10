V4: Free Fire theme, 12%-18% stable UID discount, existing Admin product/category/stock manager, server order ledger, UTR submission, Admin manual payment review and status changes, customer order tracking at /track-order.php.

PAYMENT VERIFICATION LIMIT: This build uses MANUAL UTR review, not automatic bank settlement verification. Admin must compare UTR and amount with the merchant bank/UPI statement before marking Paid. UPI redirect, screenshot or typed UTR alone is not proof. Automatic verification requires a payment gateway merchant account and server-side API/webhook credentials (Razorpay/Cashfree etc.).

DEPLOY: Backup site; upload/replace all files in public_html; ensure PHP can write orders.json in website root; change Admin password immediately; test low-value order, UTR submission and Admin verification; enable HTTPS and block public access to orders.json.
