Payme Merchant API request bodies as Payme sends them to `POST /billing/webhook/payme` (docs/13 §4.2).
Tests load a file with `paymeFixture('CreateTransaction', [...overrides])` and replace the order token, the
transaction id, the time and the amount per scenario; the shapes themselves are the ones to compare against the
Payme sandbox (docs/13 §15 p. 2). Times are milliseconds; `account.order_id` is `payment_orders.token`.
