// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, ADMIN_PAGES, resetState, getState, notice } = require( './helpers' );

/**
 * Impostazioni dell'admin: dati del titolare, retention dello storico DSAR,
 * responsabili esterni e modelli (bug 9).
 */

test.use( { storageState: ADMIN_STATE } );

test.beforeEach( async ( { request } ) => {
	await resetState( request, { titolare: false } );
} );

async function saveSettings( page ) {
	await Promise.all( [
		page.waitForURL( /dbph_msg=titolare_saved/ ),
		page.getByRole( 'button', { name: 'Salva impostazioni' } ).click(),
	] );
	await expect( notice( page, 'Dati del titolare salvati' ) ).toBeVisible();
}

test( 'salvataggio del titolare', async ( { page, request } ) => {
	await page.goto( ADMIN_PAGES.generator );
	await page.locator( '#dbph_nome' ).fill( 'Rossi & Figli S.r.l.' );
	await page.locator( '#dbph_email' ).fill( 'privacy@rossi.example' );

	await saveSettings( page );

	const { titolare } = await getState( request );
	expect( titolare.nome ).toBe( 'Rossi & Figli S.r.l.' );
	expect( titolare.email ).toBe( 'privacy@rossi.example' );
	// Anteprima aggiornata.
	await expect( page.locator( '.dbph-preview' ).getByText( 'Rossi & Figli S.r.l.' ).first() ).toBeVisible();
} );

test( 'retention dello storico DSAR limitata a 0–20 anni', async ( { page } ) => {
	await page.goto( ADMIN_PAGES.generator );
	await expect( page.locator( '#dbph_dsar_retention' ) ).toHaveValue( '5' );

	await page.locator( '#dbph_nome' ).fill( 'ACME' );
	// L'attributo max blocca il browser: lo togliamo per provare il server.
	await page.locator( '#dbph_dsar_retention' ).evaluate( ( el ) => el.removeAttribute( 'max' ) );
	await page.locator( '#dbph_dsar_retention' ).fill( '99' );
	await saveSettings( page );
	await expect( page.locator( '#dbph_dsar_retention' ) ).toHaveValue( '20' );

	await page.locator( '#dbph_dsar_retention' ).fill( '0' );
	await saveSettings( page );
	await expect( page.locator( '#dbph_dsar_retention' ) ).toHaveValue( '0' );
} );

test.describe( 'responsabili esterni', () => {

	test( 'aggiunta da modello, compilazione e salvataggio', async ( { page } ) => {
		await page.goto( ADMIN_PAGES.responsabili );
		await page.locator( 'select[name="dbph_template"]' ).selectOption( 'hosting' );
		await Promise.all( [
			page.waitForURL( /dbph_msg=template_added/ ),
			page.getByRole( 'button', { name: 'Aggiungi voce precompilata' } ).click(),
		] );
		await expect( page.getByText( 'contengono ancora un segnaposto' ) ).toBeVisible();

		await page.locator( 'input[name="dbph_resp[0][nome]"]' ).fill( 'Hosting Srl' );
		await page.locator( 'input[name="dbph_resp[0][paese]"]' ).fill( 'Germania' );
		await Promise.all( [
			page.waitForURL( /dbph_msg=responsabili_saved/ ),
			page.getByRole( 'button', { name: 'Salva responsabili' } ).click(),
		] );

		await expect( page.locator( 'input[name="dbph_resp[0][nome]"]' ) ).toHaveValue( 'Hosting Srl' );
		await expect( page.getByText( 'contengono ancora un segnaposto' ) ).toBeHidden();

		// Il responsabile dichiarato compare nella policy.
		await page.goto( ADMIN_PAGES.generator );
		await expect( page.locator( '.dbph-preview' ).getByText( 'Hosting Srl' ).first() ).toBeVisible();
	} );

	test( 'svuotando il nome la voce viene eliminata', async ( { page } ) => {
		await page.goto( ADMIN_PAGES.responsabili );
		await page.locator( 'select[name="dbph_template"]' ).selectOption( 'backup' );
		await Promise.all( [ page.waitForURL( /template_added/ ), page.getByRole( 'button', { name: 'Aggiungi voce precompilata' } ).click() ] );

		await page.locator( 'input[name="dbph_resp[0][nome]"]' ).fill( '' );
		await Promise.all( [ page.waitForURL( /responsabili_saved/ ), page.getByRole( 'button', { name: 'Salva responsabili' } ).click() ] );

		await expect( page.locator( 'input[name="dbph_resp[0][nome]"]' ) ).toHaveValue( '' );
		await expect( page.locator( 'input[name="dbph_resp[1][nome]"]' ) ).toHaveCount( 0 );
	} );

	test( 'i modelli aggiunti dal filtro compaiono nel menu (bug 9)', async ( { page, request } ) => {
		await resetState( request, { fakes: [ 'resp_template' ] } );
		await page.goto( ADMIN_PAGES.responsabili );

		const option = page.locator( 'select[name="dbph_template"] option[value="dpo_esterno"]' );
		await expect( option ).toHaveText( 'DPO esterno (E2E)' );

		await page.locator( 'select[name="dbph_template"]' ).selectOption( 'dpo_esterno' );
		await Promise.all( [ page.waitForURL( /template_added/ ), page.getByRole( 'button', { name: 'Aggiungi voce precompilata' } ).click() ] );
		await expect( page.locator( 'input[name="dbph_resp[0][nome]"]' ) ).toHaveValue( '[Nome DPO]' );
	} );
} );
