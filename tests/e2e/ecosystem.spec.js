// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, ADMIN_PAGES, BASE_URL, resetState, getState, publishPolicy } = require( './helpers' );

/**
 * L'Hub insieme agli altri plugin: WooCommerce (bridge e-commerce) e DB
 * Cookie Manager (sezione cookie, trattamenti, destinatari, versione della
 * policy collegata ai consensi del banner).
 */

test.use( { storageState: ADMIN_STATE } );

test.describe( 'WooCommerce', () => {

	test( 'trattamenti e gateway di pagamento nella policy', async ( { page, request } ) => {
		const state = await resetState( request, { woocommerce: true, fakes: [ 'woo_gateway' ] } );
		expect( state.woocommerce ).toBe( true );

		await page.goto( ADMIN_PAGES.register );
		await expect( page.getByText( 'Gestione ordini e spedizione (WooCommerce)' ) ).toBeVisible();
		await expect( page.getByText( 'Pagamenti online (WooCommerce)' ) ).toBeVisible();

		await page.goto( ADMIN_PAGES.generator );
		const preview = page.locator( '.dbph-preview' );
		await expect( preview.getByText( 'Stripe, Inc.' ) ).toBeVisible();
		await expect( preview ).toContainText( 'Limiti al diritto di cancellazione per i dati di acquisto' );
	} );

	test( 'senza gateway online nessun destinatario di pagamento', async ( { page, request } ) => {
		await resetState( request, { woocommerce: true } );

		await page.goto( ADMIN_PAGES.generator );
		await expect( page.locator( '.dbph-preview' ) ).not.toContainText( 'Stripe, Inc.' );
		await expect( page.locator( '.dbph-preview' ) ).toContainText( 'Gestione ordini e spedizione' );
	} );
} );

test.describe( 'DB Cookie Manager', () => {

	test( 'sezione cookie e trattamenti del Cookie Manager nella policy', async ( { page, request } ) => {
		const state = await resetState( request, { cookie_manager: true } );
		expect( state.cookie_manager ).toBe( true );

		await page.goto( ADMIN_PAGES.register );
		await expect( page.getByText( 'Raccolta del consenso ai cookie (DB Cookie Manager)' ) ).toBeVisible();

		await page.goto( ADMIN_PAGES.generator );
		const preview = page.locator( '.dbph-preview' );
		await expect( preview.getByRole( 'heading', { name: '4. Cookie e tecnologie simili' } ) ).toBeVisible();
		await expect( preview.getByRole( 'heading', { name: '5. Destinatari dei dati' } ) ).toBeVisible();
	} );

	test( 'con il Meta Pixel attivo Meta è tra i destinatari', async ( { page, request } ) => {
		await resetState( request, { cookie_manager: true, meta_pixel: '123456789012345' } );

		await page.goto( ADMIN_PAGES.generator );
		await expect( page.locator( '.dbph-preview' ).getByText( 'Meta Platforms Ireland Ltd (Meta Pixel)' ) ).toBeVisible();
	} );

	test( 'il consenso dal banner registra la versione corrente della policy', async ( { browser, page, request } ) => {
		await resetState( request, { cookie_manager: true } );
		await publishPolicy( page );
		const { current_version: version } = await getState( request );
		expect( version ).toBeGreaterThan( 0 );

		// Visitatore anonimo: accetta tutto dal banner.
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const front = await visitor.newPage();
		await front.route( ( url ) => ! url.href.startsWith( BASE_URL ), ( route ) => route.abort() );
		await front.goto( '/' );
		const saved = front.waitForResponse( ( r ) => r.url().includes( 'admin-ajax.php' ) && r.request().method() === 'POST' );
		await front.locator( '.dbcm-banner .dbcm-btn--primary' ).click();
		await saved;
		await visitor.close();

		expect( ( await getState( request ) ).cm_last_consent ).toMatchObject( { policy_version: String( version ) } );

		// Il registro consensi dell'Hub mostra la fonte del Cookie Manager e
		// il collegamento alla versione.
		await page.goto( ADMIN_PAGES.consents );
		const row = page.locator( 'table.widefat tbody tr' ).first();
		await expect( row ).toContainText( 'Cookie Manager' );
		await expect( row.getByRole( 'link', { name: `v#${ version }` } ) ).toBeVisible();
	} );
} );
