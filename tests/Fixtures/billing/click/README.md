Click Merchant API forms as Click posts them (application/x-www-form-urlencoded) to
`POST /billing/webhook/click/prepare` and `/complete` (docs/13 §4.3). Tests load a file through
`Tests\Support\ClickFixtures::prepare($order)` / `complete($order, $merchantPrepareId)`, which replace
`merchant_trans_id` (= `payment_orders.token`), `amount` (UZS with two decimals), `sign_time` and sign the form with the
test secret the way Click does: `md5(click_trans_id + service_id + SECRET_KEY + merchant_trans_id [+ merchant_prepare_id]
+ amount + action + sign_time)`. The shapes are the ones to compare against the Click test flow (docs/13 §15 p. 3).
