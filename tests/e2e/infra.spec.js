// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, ADMIN_PAGES, resetState, getState } = require( './helpers' );

/**
 * Smoke test dell'infrastruttura E2E: se questi falliscono, i fallimenti
 * degli altri spec non sono attendibili.
 */
test.describe( 'Infrastruttura E2E', () => {

	test( 'il reset riporta alla baseline e accetta override e seed', async ( { request } ) => {
		const base = await resetState( request );
		expect( base.titolare.nome ).toBe( 'E2E Test Srl' );
		expect( base.fakes ).toEqual( [] );
		expect( base.privacy_pages ).toEqual( [] );
		expect( base.policy_versions ).toBe( 0 );
		expect( base.current_version ).toBe( 0 );
		expect( base.dsar ).toEqual( [] );

		const custom = await resetState( request, {
			titolare: false,
			fakes: [ 'register', 'dsar', 'sconosciuto' ],
			privacy_page: true,
			seed_dsar: [ { type: 'erase', status: 'received', days_ago: 10, count: 2 } ],
			seed_versions: [ '<p>v1</p>', '<p>v2</p>' ],
		} );
		expect( custom.titolare.nome ).toBe( '' );
		expect( custom.fakes ).toEqual( [ 'register', 'dsar' ] );
		expect( custom.privacy_pages ).toHaveLength( 1 );
		expect( custom.wp_privacy_page ).toBe( custom.privacy_pages[ 0 ] );
		expect( custom.policy_versions ).toBe( 2 );
		expect( custom.current_version ).toBeGreaterThan( 0 );
		expect( custom.dsar ).toHaveLength( 2 );
		expect( custom.dsar[ 0 ] ).toMatchObject( { source: 'manual', request_type: 'erase', status: 'received' } );

		// Lo stato non deve trascinarsi al test successivo.
		const again = await resetState( request );
		expect( again.titolare.nome ).toBe( 'E2E Test Srl' );
		expect( again.fakes ).toEqual( [] );
		expect( again.privacy_pages ).toEqual( [] );
		expect( again.policy_versions ).toBe( 0 );
		expect( again.dsar ).toEqual( [] );
	} );

	test.describe( 'sessione admin', () => {
		test.use( { storageState: ADMIN_STATE } );

		test.beforeEach( async ( { request } ) => {
			await resetState( request );
		} );

		test( 'il registro trattamenti è raggiungibile', async ( { page } ) => {
			await page.goto( ADMIN_PAGES.register );
			await expect( page.locator( '.db-ui-wrap h1' ) ).toHaveText( /Registro trattamenti/ );
		} );

		test( 'i trattamenti del plugin finto compaiono nel registro', async ( { page, request } ) => {
			await resetState( request, { fakes: [ 'register' ] } );
			expect( ( await getState( request ) ).fakes ).toEqual( [ 'register' ] );

			await page.goto( ADMIN_PAGES.register );
			await expect( page.getByText( 'E2E Newsletter' ).first() ).toBeVisible();
			await expect( page.getByText( 'E2E Modulo contatti' ).first() ).toBeVisible();
		} );
	} );

} );
