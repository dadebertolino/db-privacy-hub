// @ts-check
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { ADMIN_STATE, ADMIN_PAGES, resetState, getState, publishPolicy } = require( './helpers' );

/**
 * Accessibilità (obiettivo WCAG 2.1 AA) delle pagine admin dell'Hub e della
 * policy pubblicata. L'analisi è limitata al contenuto dell'Hub: il resto
 * dell'admin è di WordPress.
 */

const WCAG = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ];

async function axeViolations( page, selector ) {
	const results = await new AxeBuilder( { page } ).include( selector ).withTags( WCAG ).analyze();
	return results.violations.map( ( v ) => `${ v.id }: ${ v.help } (${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) })` );
}

test.describe( 'pagine admin', () => {
	test.use( { storageState: ADMIN_STATE } );

	test.beforeAll( async ( { request } ) => {
		await resetState( request, {
			fakes: [ 'register', 'consents' ],
			seed_dsar: [ { type: 'erase', status: 'received', days_ago: 40 }, { type: 'export', status: 'completed' } ],
			seed_versions: [ '<p>v1</p>', '<p>v2</p>' ],
		} );
	} );

	for ( const [ name, url ] of Object.entries( ADMIN_PAGES ) ) {
		test( name, async ( { page } ) => {
			await page.goto( url );
			await expect( page.locator( '.db-ui-wrap h1' ) ).toBeVisible();
			expect( await axeViolations( page, '.db-ui-wrap' ) ).toEqual( [] );
		} );
	}
} );

test( 'policy pubblicata', async ( { browser, request } ) => {
	await resetState( request );
	const admin = await browser.newContext( { storageState: ADMIN_STATE } );
	await publishPolicy( await admin.newPage() );
	await admin.close();
	const { page_id: pageId } = await getState( request );

	const visitor = await browser.newContext();
	const page = await visitor.newPage();
	await page.goto( `/?page_id=${ pageId }` );
	await expect( page.getByRole( 'heading', { name: 'Informativa sul trattamento dei dati personali' } ) ).toBeVisible();
	expect( await axeViolations( page, '.entry-content, .wp-block-post-content' ) ).toEqual( [] );
	await visitor.close();
} );
