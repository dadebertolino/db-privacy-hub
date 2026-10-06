// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, ADMIN_PAGES, resetState, getState, notice, publishPolicy } = require( './helpers' );

/**
 * Pubblicazione della Privacy Policy come pagina WordPress: creazione,
 * aggiornamento della pagina collegata senza duplicati (bug 7),
 * sovrascrittura di una pagina scritta a mano con avviso e backup (bug 4),
 * export Markdown.
 */

test.use( { storageState: ADMIN_STATE } );

test.beforeEach( async ( { request } ) => {
	await resetState( request );
} );

test( 'la prima pubblicazione crea la pagina privacy del sito', async ( { page, request } ) => {
	await page.goto( ADMIN_PAGES.generator );
	await expect( page.locator( '#dbph_target_page' ) ).toHaveValue( 'new' );
	await expect( page.locator( '#dbph-overwrite-warning' ) ).toBeHidden();

	await publishPolicy( page );

	await expect( notice( page, 'Pagina Privacy Policy creata' ) ).toBeVisible();
	const state = await getState( request );
	expect( state.privacy_pages ).toHaveLength( 1 );
	expect( state.page_id ).toBe( state.privacy_pages[ 0 ] );
	expect( state.wp_privacy_page ).toBe( state.page_id );
	expect( state.policy_versions ).toBe( 1 );
	expect( state.current_version ).toBeGreaterThan( 0 );

	// La pagina pubblicata contiene la policy con i dati del titolare.
	await page.goto( `/?page_id=${ state.page_id }` );
	await expect( page.getByRole( 'heading', { name: 'Informativa sul trattamento dei dati personali' } ) ).toBeVisible();
	await expect( page.getByText( 'E2E Test Srl' ).first() ).toBeVisible();
} );

test( 'ripubblicare aggiorna la pagina collegata senza duplicati (bug 7)', async ( { page, request } ) => {
	await publishPolicy( page );
	const first = await getState( request );

	// La pagina collegata è la destinazione predefinita, senza avviso.
	await page.goto( ADMIN_PAGES.generator );
	await expect( page.locator( '#dbph_target_page' ) ).toHaveValue( String( first.page_id ) );
	await expect( page.locator( '#dbph-overwrite-warning' ) ).toBeHidden();

	let dialogs = 0;
	page.on( 'dialog', ( d ) => {
		dialogs++;
		d.dismiss();
	} );
	await Promise.all( [ page.waitForURL( /dbph_msg=/ ), page.locator( '#dbph-publish-btn' ).click() ] );
	await expect( notice( page, 'Pagina Privacy Policy aggiornata' ) ).toBeVisible();
	await publishPolicy( page );

	const state = await getState( request );
	expect( dialogs ).toBe( 0 );
	expect( state.privacy_pages ).toEqual( [ first.page_id ] );
	// Stesso testo: nessuna nuova versione.
	expect( state.policy_versions ).toBe( 1 );
	expect( state.current_version ).toBe( first.current_version );
} );

test.describe( 'sovrascrittura di una pagina esistente', () => {

	test.beforeEach( async ( { request } ) => {
		await resetState( request, { privacy_page: true } );
	} );

	test( 'con conferma: backup del testo sostituito, mai versione corrente (bug 4)', async ( { page, request } ) => {
		const before = await getState( request );
		const target = before.wp_privacy_page;

		await page.goto( ADMIN_PAGES.generator );
		await page.locator( '#dbph_target_page' ).selectOption( String( target ) );
		await expect( page.locator( '#dbph-overwrite-warning' ) ).toBeVisible();

		let message = '';
		page.once( 'dialog', ( d ) => {
			message = d.message();
			d.accept();
		} );
		await Promise.all( [ page.waitForURL( /dbph_msg=/ ), page.locator( '#dbph-publish-btn' ).click() ] );

		expect( message ).toContain( 'Privacy Policy' );
		await expect( notice( page, 'Pagina Privacy Policy aggiornata' ) ).toBeVisible();
		const state = await getState( request );
		expect( state.privacy_pages ).toEqual( [ target ] );
		expect( state.page_id ).toBe( target );
		// Backup + versione pubblicata; la corrente è la pubblicata.
		expect( state.policy_versions ).toBe( 2 );

		await page.goto( ADMIN_PAGES.policyHistory );
		const rows = page.locator( 'table.widefat tbody tr' );
		await expect( rows ).toHaveCount( 2 );
		await expect( rows.filter( { hasText: 'Backup pre-sovrascrittura' } ) ).toHaveCount( 1 );
		await expect( rows.first() ).toContainText( `#${ state.current_version }` );
		await expect( rows.first() ).not.toContainText( 'Backup' );
	} );

	test( 'annullando la conferma la pagina non cambia', async ( { page, request } ) => {
		const before = await getState( request );

		await page.goto( ADMIN_PAGES.generator );
		await page.locator( '#dbph_target_page' ).selectOption( String( before.wp_privacy_page ) );
		page.once( 'dialog', ( d ) => d.dismiss() );
		await page.locator( '#dbph-publish-btn' ).click();

		await expect( page ).not.toHaveURL( /dbph_msg=/ );
		const state = await getState( request );
		expect( state.page_id ).toBe( 0 );
		expect( state.policy_versions ).toBe( 0 );
	} );
} );

test( 'senza titolare la pubblicazione è bloccata', async ( { page, request } ) => {
	await resetState( request, { titolare: false } );

	await publishPolicy( page );

	await expect( notice( page, 'Compila prima i dati del titolare' ) ).toBeVisible();
	expect( ( await getState( request ) ).privacy_pages ).toEqual( [] );
} );

test( 'export Markdown', async ( { page } ) => {
	await page.goto( ADMIN_PAGES.generator );

	const [ download ] = await Promise.all( [
		page.waitForEvent( 'download' ),
		page.getByRole( 'button', { name: 'Scarica .md' } ).click(),
	] );

	expect( download.suggestedFilename() ).toMatch( /^privacy-policy-.*\.md$/ );
	const md = require( 'fs' ).readFileSync( await download.path(), 'utf8' );
	expect( md.startsWith( '## Informativa sul trattamento dei dati personali' ) ).toBe( true );
	expect( md ).toContain( '### 1. Titolare del trattamento' );
	expect( md ).not.toMatch( /<[a-z]/i );
} );
