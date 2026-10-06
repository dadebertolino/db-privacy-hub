#!/usr/bin/env bash
#
# Prepara l'ambiente wp-env per gli E2E. Fallisce (set -e) sui passi essenziali.
#
set -euo pipefail

run() { npx wp-env run cli wp "$@"; }

echo "→ Permalink pretty"
run rewrite structure '/%postname%/' --hard
run rewrite flush --hard

echo "→ DB Privacy Hub attivo"
run plugin activate db-privacy-hub || true
if ! run plugin is-active db-privacy-hub 2>/dev/null; then
	echo "::error::DB Privacy Hub non attivo. Setup fallito." >&2
	run plugin list
	exit 1
fi

echo "→ WooCommerce: installato ma NON attivo (lo accende il reset negli spec che lo usano)"
# Non ci affidiamo al download automatico di wp-env: lo installiamo qui in modo
# deterministico, sempre all'ultima versione.
if ! run plugin is-installed woocommerce 2>/dev/null; then
	run plugin install woocommerce
fi
run plugin deactivate woocommerce 2>/dev/null || true
# Impostazioni base, pronte per quando uno spec lo attiva. Negozio aperto e
# niente procedura guidata: altrimenti il primo accesso a wp-admin apre
# l'onboarding.
run option update woocommerce_default_country 'IT:TO'
run option update woocommerce_currency 'EUR'
run option update woocommerce_coming_soon 'no'
run option update woocommerce_onboarding_profile '{"skipped":true}' --format=json

echo "→ Flush rewrite finale"
run rewrite flush --hard

echo "→ Stato baseline dell'Hub"
run eval 'var_export( ! is_wp_error( dbph_e2e_reset_state() ) );'

echo "Setup E2E completato."
