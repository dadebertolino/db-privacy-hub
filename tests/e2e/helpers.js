// @ts-check
/**
 * Helper condivisi per gli E2E di DB Privacy Hub.
 */
const path = require( 'path' );

/**
 * Sessione admin salvata da auth.setup.js. Da usare negli spec admin con
 * test.use( { storageState: ADMIN_STATE } ).
 */
const ADMIN_STATE = path.join( __dirname, '.auth', 'admin.json' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

/**
 * Pagine admin dell'Hub (slug di DBPH_Admin).
 */
const ADMIN_PAGES = {
	register: '/wp-admin/admin.php?page=dbph',
	generator: '/wp-admin/admin.php?page=dbph-generator',
	responsabili: '/wp-admin/admin.php?page=dbph-responsabili',
	dsarLog: '/wp-admin/admin.php?page=dbph-dsar-log',
	dsarNew: '/wp-admin/admin.php?page=dbph-dsar-new',
	consents: '/wp-admin/admin.php?page=dbph-consents',
	policyHistory: '/wp-admin/admin.php?page=dbph-policy-history',
};

/**
 * Riporta l'Hub allo stato baseline via endpoint REST della fixture.
 * Opzioni: titolare, fakes, consents_rows, privacy_page, seed_dsar,
 * seed_versions (vedi dbph_e2e_reset_state() nella fixture).
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {object} [opts]
 * @returns {Promise<object>} Stato risultante (come getState).
 */
async function resetState( request, opts = {} ) {
	const res = await request.post( '/?rest_route=/dbph-e2e/v1/reset', { data: opts } );
	if ( ! res.ok() ) {
		throw new Error( `Reset E2E fallito (HTTP ${ res.status() }): ${ await res.text() }` );
	}
	return res.json();
}

/**
 * Legge lo stato lato server: titolare, pagine privacy, versioni della
 * policy, righe del log DSAR.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @returns {Promise<{titolare: object, fakes: string[], page_id: number, wp_privacy_page: number, privacy_pages: number[], policy_versions: number, current_version: number, dsar: object[]}>}
 */
async function getState( request ) {
	const res = await request.get( '/?rest_route=/dbph-e2e/v1/state' );
	if ( ! res.ok() ) {
		throw new Error( `Lettura stato E2E fallita (HTTP ${ res.status() }): ${ await res.text() }` );
	}
	return res.json();
}

module.exports = {
	BASE_URL,
	ADMIN_STATE,
	ADMIN_PAGES,
	resetState,
	getState,
};
