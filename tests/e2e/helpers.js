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

/**
 * Notice di esito delle azioni admin (admin_notices dell'Hub).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string|RegExp} text
 */
function notice( page, text ) {
	return page.locator( '#wpbody-content .updated, #wpbody-content .error, #wpbody-content .notice' ).filter( { hasText: text } );
}

/**
 * Pubblica la policy dalla pagina del generatore con la destinazione
 * indicata (default: quella preselezionata). Accetta l'eventuale conferma
 * di sovrascrittura.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [target] Valore dell'opzione: 'new' o ID pagina.
 */
async function publishPolicy( page, target ) {
	await page.goto( ADMIN_PAGES.generator );
	if ( target !== undefined ) {
		await page.locator( '#dbph_target_page' ).selectOption( String( target ) );
	}
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await Promise.all( [
		page.waitForURL( /dbph_msg=/ ),
		page.locator( '#dbph-publish-btn' ).click(),
	] );
}

/**
 * Scarica un file da un link admin (export CSV) con la sessione della
 * pagina e ne restituisce risposta e testo.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} link
 */
async function fetchLink( page, link ) {
	const href = await link.getAttribute( 'href' );
	const res = await page.request.get( href );
	return { res, body: await res.text() };
}

/**
 * Righe di un CSV generato da fputcsv (virgola, campi tra virgolette, a
 * capo ammessi dentro i campi). Il BOM iniziale viene ignorato.
 *
 * @param {string} body
 * @returns {string[][]}
 */
function parseCsv( body ) {
	const text = body.replace( /^\uFEFF/, '' );
	const rows = [];
	let row = [];
	let cur = '';
	let quoted = false;
	for ( let i = 0; i < text.length; i++ ) {
		const ch = text[ i ];
		if ( quoted ) {
			if ( ch === '"' && text[ i + 1 ] === '"' ) {
				cur += '"';
				i++;
			} else if ( ch === '"' ) {
				quoted = false;
			} else {
				cur += ch;
			}
		} else if ( ch === '"' ) {
			quoted = true;
		} else if ( ch === ',' ) {
			row.push( cur );
			cur = '';
		} else if ( ch === '\n' || ch === '\r' ) {
			if ( ch === '\r' && text[ i + 1 ] === '\n' ) {
				i++;
			}
			row.push( cur );
			rows.push( row );
			row = [];
			cur = '';
		} else {
			cur += ch;
		}
	}
	if ( cur !== '' || row.length ) {
		row.push( cur );
		rows.push( row );
	}
	return rows;
}

module.exports = {
	BASE_URL,
	ADMIN_STATE,
	ADMIN_PAGES,
	resetState,
	getState,
	notice,
	publishPolicy,
	fetchLink,
	parseCsv,
};
