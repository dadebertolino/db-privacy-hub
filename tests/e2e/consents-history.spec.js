// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, ADMIN_PAGES, resetState, fetchLink, parseCsv, publishPolicy } = require( './helpers' );

/**
 * Registro consensi unificato (fonti dichiarate da altri plugin, filtri,
 * export CSV, fonti rotte) e storico delle versioni della policy.
 */

test.use( { storageState: ADMIN_STATE } );

test.describe( 'registro consensi', () => {

	test( 'righe della fonte, filtro e export CSV', async ( { page, request } ) => {
		await resetState( request, { fakes: [ 'consents' ], consents_rows: 4 } );

		await page.goto( ADMIN_PAGES.consents );
		const rows = page.locator( 'table.widefat tbody tr' );
		await expect( rows ).toHaveCount( 4 );
		await expect( rows.first() ).toContainText( 'E2E Consensi' );

		await page.locator( '#dbph-filter-subject' ).fill( 'u***2' );
		await page.getByRole( 'button', { name: 'Filtra' } ).click();
		await expect( page ).toHaveURL( /subject=/ );

		const { res, body } = await fetchLink( page, page.locator( 'a[href*="dbph_export_consents_csv"]' ) );
		expect( res.headers()[ 'content-type' ] ).toContain( 'text/csv' );
		const csv = parseCsv( body );
		expect( csv[ 0 ] ).toEqual( [ 'Timestamp', 'Fonte', 'Identificativo', 'Tipo consenso', 'Testo consenso', 'Versione Privacy Policy', 'Extra' ] );
		expect( csv.length ).toBeGreaterThan( 1 );
	} );

	test( 'una fonte rotta e righe malformate non rompono la pagina (bug 3, 11)', async ( { page, request } ) => {
		await resetState( request, { fakes: [ 'consents', 'bad_consents', 'throwing_consents' ], consents_rows: 2 } );

		const res = await page.goto( ADMIN_PAGES.consents );
		expect( res.status() ).toBe( 200 );
		await expect( page.locator( 'table.widefat tbody tr' ) ).toHaveCount( 3 );
		await expect( page.locator( '#wpbody-content' ) ).not.toContainText( 'Fatal error' );
		await expect( page.locator( 'table.widefat' ) ).not.toContainText( 'Array' );
	} );
} );

test.describe( 'storico della policy', () => {

	test( 'versioni, vista singola e differenze', async ( { page, request } ) => {
		await resetState( request, { seed_versions: [ '<h2>Policy</h2><p>Conservazione 12 mesi.</p>', '<h2>Policy</h2><p>Conservazione 24 mesi.</p>' ] } );

		await page.goto( ADMIN_PAGES.policyHistory );
		const rows = page.locator( 'table.widefat tbody tr' );
		await expect( rows ).toHaveCount( 2 );

		await rows.first().getByRole( 'link', { name: 'Visualizza' } ).click();
		await expect( page.locator( '.dbph-preview' ) ).toContainText( 'Conservazione 24 mesi.' );

		await page.goto( ADMIN_PAGES.policyHistory );
		await rows.first().getByRole( 'link', { name: 'Diff vs precedente' } ).click();
		await expect( page.getByRole( 'heading', { name: /Differenze/ } ) ).toBeVisible();
		await expect( page.locator( 'table.diff' ) ).toContainText( '12' );
		await expect( page.locator( 'table.diff' ) ).toContainText( '24' );
	} );

	test( 'una modifica manuale della pagina pubblicata crea una versione', async ( { page, request } ) => {
		await resetState( request );
		await publishPolicy( page );
		const { getState } = require( './helpers' );
		const state = await getState( request );

		// Modifica dall'editor classico di WordPress via REST (come l'editor a blocchi).
		const nonce = await page.evaluate( async () => {
			const r = await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' );
			return r.text();
		} );
		const res = await page.request.post( `/?rest_route=/wp/v2/pages/${ state.page_id }`, {
			headers: { 'X-WP-Nonce': nonce },
			data: { content: '<p>Testo ritoccato a mano.</p>' },
		} );
		expect( res.ok() ).toBe( true );

		await page.goto( ADMIN_PAGES.policyHistory );
		await expect( page.locator( 'table.widefat tbody tr' ).first() ).toContainText( 'Modifica manuale' );
		expect( ( await getState( request ) ).current_version ).toBeGreaterThan( state.current_version );
	} );
} );
