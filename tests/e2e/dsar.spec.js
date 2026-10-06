// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, ADMIN_PAGES, resetState, getState, notice, fetchLink, parseCsv } = require( './helpers' );

/**
 * Storico DSAR: richieste avviate dagli strumenti privacy di WordPress
 * (Strumenti → Esporta / Cancella dati personali), richieste manuali,
 * export CSV, avviso singolo (bug 8).
 */

test.use( { storageState: ADMIN_STATE } );

/**
 * Crea una richiesta dagli strumenti di WordPress senza email di conferma
 * (la richiesta nasce già confermata).
 *
 * @param {import('@playwright/test').Page} page
 * @param {'export'|'erase'} kind
 * @param {string} email
 */
async function wpRequest( page, kind, email ) {
	await page.goto( kind === 'export' ? '/wp-admin/export-personal-data.php' : '/wp-admin/erase-personal-data.php' );
	await page.locator( '#username_or_email_for_privacy_request' ).fill( email );
	await page.locator( '#send_confirmation_email' ).uncheck();
	await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( '#submit' ).click() ] );
	return page.locator( 'table.wp-list-table tbody tr' ).filter( { hasText: email } );
}

async function lastDsar( request ) {
	const { dsar } = await getState( request );
	return dsar[ dsar.length - 1 ];
}

test.describe( 'strumenti privacy di WordPress', () => {

	test( 'export: la richiesta arriva nello storico ed è evasa', async ( { page, request } ) => {
		await resetState( request, { fakes: [ 'dsar' ] } );

		const row = await wpRequest( page, 'export', 'export@e2e.test' );
		await expect( row ).toHaveCount( 1 );
		expect( await lastDsar( request ) ).toMatchObject( { source: 'wp_native', request_type: 'export', status: 'confirmed' } );

		await row.hover();
		await row.getByRole( 'button', { name: 'Download personal data', exact: true } ).click();
		await expect.poll( async () => ( await lastDsar( request ) ).status, { timeout: 20000 } ).toBe( 'completed' );

		await page.goto( ADMIN_PAGES.dsarLog );
		const logRow = page.locator( 'table.widefat tbody tr' ).first();
		await expect( logRow ).toContainText( 'e****t@e2e.test' );
		await expect( logRow ).toContainText( 'Completata' );
		await expect( logRow ).not.toContainText( 'export@e2e.test' );
	} );

	test( 'cancellazione con dati trattenuti: parziale, con motivazione', async ( { page, request } ) => {
		await resetState( request, { fakes: [ 'dsar' ] } );

		const row = await wpRequest( page, 'erase', 'erase@e2e.test' );
		await row.hover();
		await row.getByRole( 'button', { name: 'Erase personal data', exact: true } ).click();
		await expect.poll( async () => ( await lastDsar( request ) ).status, { timeout: 20000 } ).toBe( 'partial' );

		await page.goto( ADMIN_PAGES.dsarLog );
		await expect( page.locator( 'table.widefat tbody tr' ).first() ).toContainText( 'Parzialmente completata' );

		const { res, body } = await fetchLink( page, page.locator( 'a[href*="dbph_export_dsar_csv"]' ) );
		expect( res.headers()[ 'content-type' ] ).toContain( 'text/csv' );
		const csv = parseCsv( body );
		expect( csv[ 0 ] ).toContain( 'Email mascherata' );
		const line = csv.find( ( r ) => r.includes( 'e***e@e2e.test' ) );
		expect( line ).toBeTruthy();
		expect( line.join( '|' ) ).toContain( 'E2E: dati fiscali conservati 10 anni.' );
		expect( body ).not.toContain( 'erase@e2e.test' );
	} );

	test( 'un eraser che lancia non blocca gli altri (bug 3)', async ( { page, request } ) => {
		await resetState( request, { fakes: [ 'dsar', 'throwing_dsar' ] } );

		const row = await wpRequest( page, 'erase', 'rotto@e2e.test' );
		await row.hover();
		await row.getByRole( 'button', { name: 'Erase personal data', exact: true } ).click();

		await expect.poll( async () => ( await lastDsar( request ) ).status, { timeout: 20000 } ).toBe( 'partial' );
		await expect( row ).not.toContainText( 'error', { ignoreCase: true } );
	} );
} );

test.describe( 'richieste manuali', () => {

	test.beforeEach( async ( { request } ) => {
		await resetState( request );
	} );

	test( 'registrazione, modifica ed eliminazione con un solo avviso (bug 8)', async ( { page, request } ) => {
		await page.goto( ADMIN_PAGES.dsarNew );
		await page.locator( '#dbph_request_type' ).selectOption( 'rectify' );
		await page.locator( '#dbph_email' ).fill( 'pec.utente@e2e.test' );
		await page.locator( '#dbph_channel' ).selectOption( 'pec' );
		await page.locator( '#dbph_description' ).fill( 'Correggere l\'indirizzo di spedizione.' );
		await Promise.all( [ page.waitForURL( /manual_saved/ ), page.getByRole( 'button', { name: 'Registra richiesta' } ).click() ] );

		await expect( page.getByText( /Richiesta DSAR manuale (registrata|salvata)/ ) ).toHaveCount( 1 );
		expect( await lastDsar( request ) ).toMatchObject( { source: 'manual', request_type: 'rectify', status: 'received' } );
		const row = page.locator( 'table.widefat tbody tr' ).first();
		await expect( row ).toContainText( 'Rettifica (art. 16 GDPR)' );

		await row.getByRole( 'link', { name: 'Modifica' } ).click();
		await page.locator( '#dbph_status' ).selectOption( 'completed' );
		await Promise.all( [ page.waitForURL( /manual_updated/ ), page.getByRole( 'button', { name: 'Salva modifiche' } ).click() ] );
		await expect( page.getByText( /Richiesta DSAR (manuale )?aggiornata/ ) ).toHaveCount( 1 );
		expect( ( await lastDsar( request ) ).status ).toBe( 'completed' );

		await page.locator( 'table.widefat tbody tr' ).first().getByRole( 'link', { name: 'Modifica' } ).click();
		page.once( 'dialog', ( d ) => d.accept() );
		await Promise.all( [ page.waitForURL( /manual_deleted/ ), page.locator( 'a[href*="dbph_delete_manual_dsar"]' ).click() ] );
		await expect( page.getByText( /Richiesta DSAR (manuale )?eliminata/ ) ).toHaveCount( 1 );
		expect( ( await getState( request ) ).dsar ).toEqual( [] );
	} );

	test( 'cruscotto: aperte, scadute e in scadenza', async ( { page, request } ) => {
		await resetState( request, {
			seed_dsar: [
				{ type: 'erase', status: 'received', days_ago: 40 },
				{ type: 'export', status: 'in_progress', days_ago: 25 },
				{ type: 'object', status: 'received', days_ago: 1 },
				{ type: 'erase', status: 'partial', days_ago: 50 },
				{ type: 'export', status: 'expired', days_ago: 50 },
			],
		} );

		await page.goto( ADMIN_PAGES.dsarLog );
		const card = page.locator( '.db-ui-card-body' ).first();
		const value = ( label ) => card.locator( ':scope > div' ).filter( { hasText: label } ).locator( 'strong' );
		await expect( value( 'Totali' ) ).toHaveText( '5' );
		await expect( value( 'Aperte' ) ).toHaveText( '3' );
		await expect( value( 'Cancellazioni evase' ) ).toHaveText( '1' );
		await expect( value( 'Scadute' ) ).toHaveText( '1' );
		await expect( value( 'In scadenza' ) ).toHaveText( '1' );
		await expect( page.locator( 'table.widefat tbody tr[style*="fef2f2"]' ) ).toHaveCount( 1 );
	} );
} );
