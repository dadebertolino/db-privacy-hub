<?php
/**
 * Export Markdown della policy (DBPH_Policy_Generator::html_to_markdown).
 * Bug 12: <b>/<i> catturavano <br>, <blockquote>, <img>, <iframe>; liste
 * annidate appiattite.
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class MarkdownTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
	}

	private function md( $html ) {
		return DBPH_Policy_Generator::html_to_markdown( $html );
	}

	public function test_titoli(): void {
		$this->assertSame(
			"## Uno\n\n### Due\n\n#### Tre\n\n##### Quattro",
			$this->md( '<h2>Uno</h2><h3 id="dbph-x">Due</h3><h4>Tre</h4><h5>Quattro</h5>' )
		);
	}

	public function test_grassetto_corsivo_e_codice(): void {
		$this->assertSame(
			'**forte** **b** *em* *i* `code`',
			$this->md( '<strong>forte</strong> <b class="x">b</b> <em>em</em> <i>i</i> <code>code</code>' )
		);
	}

	public function test_br_non_diventa_grassetto(): void {
		$this->assertSame( "Riga uno\nRiga **due**", $this->md( '<p>Riga uno<br>Riga <b>due</b></p>' ) );
	}

	public function test_blockquote_non_diventa_grassetto(): void {
		$md = $this->md( '<blockquote><p>Citazione</p></blockquote><p>Dopo <b>b</b></p>' );

		$this->assertStringContainsString( '> Citazione', $md );
		$this->assertStringNotContainsString( '**Citazione', $md );
		$this->assertStringContainsString( 'Dopo **b**', $md );
	}

	public function test_img_e_iframe_non_diventano_corsivo(): void {
		$md = $this->md( '<p>Testo <img src="a.png" alt="x"> e <i>corsivo</i><iframe src="https://x"></iframe></p>' );

		$this->assertSame( 'Testo  e *corsivo*', $md );
	}

	public function test_paragrafo_non_cattura_pre(): void {
		$this->assertSame( 'codice', $this->md( '<pre>codice</pre>' ) );
	}

	public function test_link(): void {
		$this->assertSame(
			'Scrivi a [privacy@x.it](mailto:privacy@x.it).',
			$this->md( '<p>Scrivi a <a href="mailto:privacy@x.it">privacy@x.it</a>.</p>' )
		);
	}

	public function test_lista_semplice_e_numerata(): void {
		$this->assertSame(
			"- uno\n- due\n\n1. primo\n2. secondo",
			$this->md( "<ul>\n<li>uno</li>\n<li>due</li>\n</ul><ol><li>primo</li><li>secondo</li></ol>" )
		);
	}

	public function test_liste_annidate_indentate(): void {
		$this->assertSame(
			"- uno\n  - uno.a\n  - uno.b\n    1. profondo\n- due",
			$this->md( '<ul><li>uno<ul><li>uno.a</li><li>uno.b<ol><li>profondo</li></ol></li></ul></li><li>due</li></ul>' )
		);
	}

	public function test_tabella(): void {
		$this->assertSame(
			"| Nome | Durata |\n| --- | --- |\n| _ga | *2 anni* |",
			$this->md( '<table><tr><th>Nome</th><th>Durata</th></tr><tr><td>_ga</td><td><em>2 anni</em></td></tr></table>' )
		);
	}

	public function test_entita_e_separatore(): void {
		$this->assertSame( "L'utente &\n\n---", $this->md( '<p>L&#039;utente &amp;</p><hr>' ) );
	}

	public function test_la_policy_generata_si_converte_senza_tag_residui(): void {
		update_option( 'dbph_titolare_nome', 'ACME' );
		$md = $this->md( DBPH_Policy_Generator::generate() );

		$this->assertStringStartsWith( '## Informativa sul trattamento dei dati personali', $md );
		$this->assertStringContainsString( '### 1. Titolare del trattamento', $md );
		$this->assertStringContainsString( "1. [Titolare del trattamento](#dbph-titolare)", $md );
		$this->assertDoesNotMatchRegularExpression( '/<[a-z]/i', $md );
	}
}
