<?php
/**
 * DBPH_Admin — Interfaccia amministrativa del DB Privacy Hub.
 *
 * Aggiunge un menu top-level "Privacy" con due sotto-pagine:
 *  - Registro trattamenti  (vista del registro raccolto via filter)
 *  - Genera Privacy Policy (form titolare + opzioni pagina + bottoni azione)
 *
 * Gestisce inoltre i seguenti admin_post handler:
 *  - dbph_save_titolare    Salva i dati del titolare e le opzioni pagina
 *  - dbph_create_page      Crea (o aggiorna) la pagina WordPress della Privacy Policy
 *  - dbph_download_md      Scarica il documento come file .md
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Admin' ) ) {

	class DBPH_Admin {

		const MENU_SLUG     = 'dbph';
		const PAGE_REGISTER = 'dbph-register';
		const PAGE_GENERATOR = 'dbph-generator';

		const NONCE_SAVE     = 'dbph_save_titolare';
		const NONCE_CREATE   = 'dbph_create_page';
		const NONCE_DOWNLOAD = 'dbph_download_md';
		const NONCE_RESP     = 'dbph_save_responsabili';

		const PAGE_RESPONSABILI = 'dbph-responsabili';
		const PAGE_DSAR_LOG     = 'dbph-dsar-log';
		const PAGE_POLICY_HIST  = 'dbph-policy-history';

		public static function init() {
			if ( ! is_admin() ) {
				return;
			}

			add_action( 'admin_menu',                          array( __CLASS__, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts',               array( __CLASS__, 'enqueue_assets' ) );

			add_action( 'admin_post_dbph_save_titolare',       array( __CLASS__, 'handle_save_titolare' ) );
			add_action( 'admin_post_dbph_create_page',         array( __CLASS__, 'handle_create_page' ) );
			add_action( 'admin_post_dbph_download_md',         array( __CLASS__, 'handle_download_md' ) );
			add_action( 'admin_post_dbph_save_responsabili',   array( __CLASS__, 'handle_save_responsabili' ) );

			add_action( 'admin_notices',                       array( __CLASS__, 'admin_notices' ) );
		}

		/* =====================================================================
		 * Menu
		 * ================================================================== */

		public static function register_menu() {
			add_menu_page(
				__( 'DB Privacy Hub', 'db-privacy-hub' ),
				__( 'Privacy', 'db-privacy-hub' ),
				'manage_options',
				self::MENU_SLUG,
				array( __CLASS__, 'render_register' ),
				'dashicons-shield-alt',
				59
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Registro trattamenti', 'db-privacy-hub' ),
				__( 'Registro trattamenti', 'db-privacy-hub' ),
				'manage_options',
				self::MENU_SLUG,
				array( __CLASS__, 'render_register' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Genera Privacy Policy', 'db-privacy-hub' ),
				__( 'Genera Privacy Policy', 'db-privacy-hub' ),
				'manage_options',
				self::PAGE_GENERATOR,
				array( __CLASS__, 'render_generator' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Responsabili esterni', 'db-privacy-hub' ),
				__( 'Responsabili esterni', 'db-privacy-hub' ),
				'manage_options',
				self::PAGE_RESPONSABILI,
				array( __CLASS__, 'render_responsabili' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Storico richieste DSAR', 'db-privacy-hub' ),
				__( 'Storico DSAR', 'db-privacy-hub' ),
				'manage_options',
				self::PAGE_DSAR_LOG,
				array( __CLASS__, 'render_dsar_log' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Storico Privacy Policy', 'db-privacy-hub' ),
				__( 'Storico Policy', 'db-privacy-hub' ),
				'manage_options',
				self::PAGE_POLICY_HIST,
				array( __CLASS__, 'render_policy_history' )
			);
		}

		/* =====================================================================
		 * Assets
		 * ================================================================== */

		public static function enqueue_assets( $hook ) {
			// Carica solo nelle pagine admin del plugin.
			if ( strpos( (string) $hook, 'dbph' ) === false && strpos( (string) $hook, self::MENU_SLUG ) === false ) {
				return;
			}

			wp_enqueue_style(
				'db-admin-ui',
				DBPH_URL . 'assets/css/db-admin-ui.css',
				array(),
				DBPH_VERSION
			);
			wp_enqueue_style(
				'dbph-admin',
				DBPH_URL . 'assets/css/admin.css',
				array( 'db-admin-ui' ),
				DBPH_VERSION
			);
		}

		/* =====================================================================
		 * Admin notices (post-action feedback)
		 * ================================================================== */

		public static function admin_notices() {
			if ( ! isset( $_GET['dbph_msg'] ) ) {
				return;
			}
			$msg = sanitize_key( wp_unslash( $_GET['dbph_msg'] ) );

			$map = array(
				'titolare_saved'    => array( 'success', __( 'Dati del titolare salvati.', 'db-privacy-hub' ) ),
				'page_created'      => array( 'success', __( 'Pagina Privacy Policy creata e impostata come pagina privacy del sito.', 'db-privacy-hub' ) ),
				'page_updated'      => array( 'success', __( 'Pagina Privacy Policy aggiornata.', 'db-privacy-hub' ) ),
				'page_error'        => array( 'error',   __( 'Errore durante la creazione/aggiornamento della pagina.', 'db-privacy-hub' ) ),
				'no_titolare'       => array( 'warning', __( 'Compila prima i dati del titolare, poi rigenera il documento.', 'db-privacy-hub' ) ),
				'responsabili_saved' => array( 'success', __( 'Elenco responsabili esterni aggiornato.', 'db-privacy-hub' ) ),
			);
			if ( ! isset( $map[ $msg ] ) ) {
				return;
			}
			list( $type, $text ) = $map[ $msg ];
			$class = $type === 'success' ? 'updated' : ( $type === 'error' ? 'error' : 'notice notice-warning' );
			echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		}

		/* =====================================================================
		 * Render: Registro trattamenti
		 * ================================================================== */

		public static function render_register() {
			$register = DBPH_Register::collect();
			$counts   = DBPH_Register::count_by_source();
			?>
			<div class="wrap db-ui-wrap">
				<h1><?php esc_html_e( 'Registro trattamenti', 'db-privacy-hub' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Elenco dei trattamenti dichiarati dai plugin DB attivi sul sito tramite il filter dbph_processing_register. Questa è la fonte dati su cui si basa la Privacy Policy generata.', 'db-privacy-hub' ); ?>
				</p>

				<?php if ( empty( $register ) ) : ?>
					<div class="db-ui-alert db-ui-alert-warning">
						<span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
						<span><?php esc_html_e( 'Nessun trattamento dichiarato. Verifica che i plugin DB (Cookie Manager, Form Builder, SEO Manager) siano attivi.', 'db-privacy-hub' ); ?></span>
					</div>
				<?php else : ?>
					<div class="db-ui-alert db-ui-alert-info">
						<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
						<span>
							<?php
							printf(
								/* translators: 1: numero trattamenti, 2: numero plugin */
								esc_html__( '%1$d trattamenti dichiarati da %2$d plugin DB.', 'db-privacy-hub' ),
								(int) count( $register ),
								(int) count( $counts )
							);
							?>
						</span>
					</div>

					<?php foreach ( $register as $entry ) :
						if ( ! is_array( $entry ) || empty( $entry['label'] ) ) {
							continue;
						}
						$is_legacy = ! empty( $entry['_legacy'] );
						$is_active = ( ( $entry['status'] ?? '' ) === 'active' );
					?>
						<div class="db-ui-card">
							<div class="db-ui-card-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
								<h3 style="margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
									<?php echo esc_html( $entry['label'] ); ?>
									<?php if ( $is_legacy ) : ?>
										<span class="db-ui-badge db-ui-badge-warning"
										      title="<?php esc_attr_e( 'Voce dichiarata via filter legacy dbseo_processing_register. Il plugin che la dichiara dovrebbe essere aggiornato.', 'db-privacy-hub' ); ?>"
										      style="font-size:11px;font-weight:normal">
											⚠️ <?php esc_html_e( 'Filter legacy', 'db-privacy-hub' ); ?>
										</span>
									<?php endif; ?>
								</h3>
								<span class="db-ui-badge <?php echo $is_active ? 'db-ui-badge-success' : 'db-ui-badge-muted'; ?>">
									<?php echo $is_active ? esc_html__( 'Attivo', 'db-privacy-hub' ) : esc_html__( 'Disattivo', 'db-privacy-hub' ); ?>
								</span>
							</div>
							<div class="db-ui-card-body">
								<?php if ( ! empty( $entry['purpose'] ) ) : ?>
									<p><strong><?php esc_html_e( 'Finalità:', 'db-privacy-hub' ); ?></strong> <?php echo esc_html( $entry['purpose'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $entry['legal_basis'] ) ) : ?>
									<p><strong><?php esc_html_e( 'Base giuridica:', 'db-privacy-hub' ); ?></strong> <?php echo esc_html( $entry['legal_basis'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $entry['data_collected'] ) ) : ?>
									<p><strong><?php esc_html_e( 'Dati raccolti:', 'db-privacy-hub' ); ?></strong> <?php echo esc_html( $entry['data_collected'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $entry['retention'] ) ) : ?>
									<p><strong><?php esc_html_e( 'Conservazione:', 'db-privacy-hub' ); ?></strong> <?php echo esc_html( $entry['retention'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $entry['transfers'] ) ) : ?>
									<p style="margin-bottom:0"><strong><?php esc_html_e( 'Trasferimenti:', 'db-privacy-hub' ); ?></strong> <?php echo esc_html( $entry['transfers'] ); ?></p>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>

					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_GENERATOR ) ); ?>" class="db-ui-btn db-ui-btn-primary">
							<?php esc_html_e( 'Genera Privacy Policy →', 'db-privacy-hub' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		/* =====================================================================
		 * Render: Generator
		 * ================================================================== */

		public static function render_generator() {
			$titolare = DBPH_Policy_Generator::get_titolare();
			$page_title = (string) get_option( 'dbph_page_title', __( 'Privacy Policy', 'db-privacy-hub' ) );
			$page_slug  = (string) get_option( 'dbph_page_slug', 'privacy-policy' );
			$current_page_id = (int) get_option( 'dbph_page_id', 0 );

			$current_page = $current_page_id > 0 ? get_post( $current_page_id ) : null;
			if ( $current_page && ( $current_page->post_status === 'trash' || $current_page->post_type !== 'page' ) ) {
				$current_page = null;
			}

			$preview_html = DBPH_Policy_Generator::generate();
			?>
			<div class="wrap db-ui-wrap">
				<h1><?php esc_html_e( 'Genera Privacy Policy', 'db-privacy-hub' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Compila i dati del titolare, scegli come chiamare la pagina, poi pubblica. La Privacy Policy verrà composta automaticamente raccogliendo i trattamenti dichiarati da tutti i plugin DB attivi.', 'db-privacy-hub' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="db-ui-form">
					<input type="hidden" name="action" value="dbph_save_titolare">
					<?php wp_nonce_field( self::NONCE_SAVE, '_dbph_nonce' ); ?>

					<h2><?php esc_html_e( 'Dati del titolare', 'db-privacy-hub' ); ?></h2>
					<div class="db-ui-card">
						<div class="db-ui-card-body">
							<div class="db-ui-field">
								<label for="dbph_nome"><?php esc_html_e( 'Nome / Ragione sociale *', 'db-privacy-hub' ); ?></label>
								<input type="text" id="dbph_nome" name="dbph[nome]" value="<?php echo esc_attr( $titolare['nome'] ); ?>" class="regular-text" required>
							</div>
							<div class="db-ui-field">
								<label for="dbph_piva"><?php esc_html_e( 'P.IVA / Codice Fiscale', 'db-privacy-hub' ); ?></label>
								<input type="text" id="dbph_piva" name="dbph[piva]" value="<?php echo esc_attr( $titolare['piva'] ); ?>" class="regular-text">
							</div>
							<div class="db-ui-field">
								<label for="dbph_indirizzo"><?php esc_html_e( 'Indirizzo', 'db-privacy-hub' ); ?></label>
								<input type="text" id="dbph_indirizzo" name="dbph[indirizzo]" value="<?php echo esc_attr( $titolare['indirizzo'] ); ?>" class="regular-text">
							</div>
							<div class="db-ui-field">
								<label for="dbph_email"><?php esc_html_e( 'Email contatto privacy', 'db-privacy-hub' ); ?></label>
								<input type="email" id="dbph_email" name="dbph[email]" value="<?php echo esc_attr( $titolare['email'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Se vuoto, viene usata l\'email amministrativa del sito.', 'db-privacy-hub' ); ?></p>
							</div>
							<div class="db-ui-field">
								<label for="dbph_pec"><?php esc_html_e( 'PEC (opzionale)', 'db-privacy-hub' ); ?></label>
								<input type="email" id="dbph_pec" name="dbph[pec]" value="<?php echo esc_attr( $titolare['pec'] ); ?>" class="regular-text">
							</div>
							<div class="db-ui-field">
								<label for="dbph_dpo"><?php esc_html_e( 'DPO / Responsabile della Protezione dei Dati (opzionale)', 'db-privacy-hub' ); ?></label>
								<input type="text" id="dbph_dpo" name="dbph[dpo]" value="<?php echo esc_attr( $titolare['dpo'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Nome del DPO ed email di contatto, se nominato.', 'db-privacy-hub' ); ?></p>
							</div>
						</div>
					</div>

					<h2><?php esc_html_e( 'Pagina Privacy Policy', 'db-privacy-hub' ); ?></h2>
					<div class="db-ui-card">
						<div class="db-ui-card-body">
							<div class="db-ui-field">
								<label for="dbph_page_title"><?php esc_html_e( 'Titolo della pagina', 'db-privacy-hub' ); ?></label>
								<input type="text" id="dbph_page_title" name="dbph[page_title]" value="<?php echo esc_attr( $page_title ); ?>" class="regular-text">
							</div>
							<div class="db-ui-field">
								<label for="dbph_page_slug"><?php esc_html_e( 'Slug URL', 'db-privacy-hub' ); ?></label>
								<input type="text" id="dbph_page_slug" name="dbph[page_slug]" value="<?php echo esc_attr( $page_slug ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'L\'URL finale sarà:', 'db-privacy-hub' ); ?> <code><?php echo esc_html( home_url( '/' ) ); ?><span id="dbph-slug-preview"><?php echo esc_html( $page_slug ); ?></span>/</code></p>
							</div>
						</div>
					</div>

					<p>
						<button type="submit" class="db-ui-btn db-ui-btn-primary">
							<?php esc_html_e( 'Salva impostazioni', 'db-privacy-hub' ); ?>
						</button>
					</p>
				</form>

				<h2><?php esc_html_e( 'Azioni', 'db-privacy-hub' ); ?></h2>
				<div class="db-ui-card">
					<div class="db-ui-card-body">
						<?php if ( ! DBPH_Policy_Generator::is_titolare_configured() ) : ?>
							<div class="db-ui-alert db-ui-alert-warning">
								<span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
								<span><?php esc_html_e( 'Compila almeno il nome del titolare e salva, prima di pubblicare la pagina.', 'db-privacy-hub' ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( $current_page ) : ?>
							<p>
								<?php
								printf(
									/* translators: 1: titolo pagina, 2: link edit, 3: link visualizza */
									wp_kses(
										__( 'Pagina attualmente collegata: <strong>%1$s</strong> — <a href="%2$s">modifica</a> · <a href="%3$s" target="_blank" rel="noopener">visualizza</a>', 'db-privacy-hub' ),
										array(
											'strong' => array(),
											'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array() ),
										)
									),
									esc_html( $current_page->post_title ),
									esc_url( get_edit_post_link( $current_page->ID ) ),
									esc_url( get_permalink( $current_page->ID ) )
								);
								?>
							</p>
						<?php endif; ?>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="dbph-publish-form" style="margin-bottom:12px">
							<input type="hidden" name="action" value="dbph_create_page">
							<?php wp_nonce_field( self::NONCE_CREATE, '_dbph_nonce' ); ?>

							<?php
							$candidates = self::get_candidate_pages( $current_page ? $current_page->ID : 0 );
							?>

							<div class="db-ui-field" style="max-width:560px">
								<label for="dbph_target_page"><?php esc_html_e( 'Pagina di destinazione', 'db-privacy-hub' ); ?></label>
								<select id="dbph_target_page" name="dbph_target_page" class="regular-text" style="width:100%">
									<?php
									$selected_target = $current_page ? (string) $current_page->ID : 'new';
									?>
									<option value="new" <?php selected( $selected_target, 'new' ); ?>>
										<?php esc_html_e( '— Crea nuova pagina —', 'db-privacy-hub' ); ?>
									</option>
									<?php if ( ! empty( $candidates ) ) : ?>
										<optgroup label="<?php esc_attr_e( 'Pagine esistenti suggerite', 'db-privacy-hub' ); ?>">
											<?php foreach ( $candidates as $cand ) : ?>
												<option value="<?php echo (int) $cand->ID; ?>"
												        data-title="<?php echo esc_attr( $cand->post_title ); ?>"
												        <?php selected( $selected_target, (string) $cand->ID ); ?>>
													<?php
													printf(
														/* translators: 1: titolo pagina, 2: ID, 3: stato */
														esc_html__( '%1$s (ID %2$d, %3$s)', 'db-privacy-hub' ),
														esc_html( $cand->post_title ),
														(int) $cand->ID,
														esc_html( $cand->post_status )
													);
													?>
												</option>
											<?php endforeach; ?>
										</optgroup>
									<?php endif; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Selezionando una pagina esistente, il suo contenuto verrà sovrascritto con la Privacy Policy generata. Titolo e slug della pagina esistente non vengono modificati. Una revisione viene salvata automaticamente da WordPress (recuperabile da Pagine → Modifica → Revisioni).', 'db-privacy-hub' ); ?>
								</p>
							</div>

							<div id="dbph-overwrite-warning" class="db-ui-alert db-ui-alert-warning" style="display:none;margin:8px 0">
								<span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
								<span>
									<?php esc_html_e( 'Stai per sovrascrivere il contenuto di una pagina esistente. La modifica è recuperabile dalle revisioni WordPress della pagina, ma agisce immediatamente.', 'db-privacy-hub' ); ?>
								</span>
							</div>

							<button type="submit" class="db-ui-btn db-ui-btn-primary" id="dbph-publish-btn">
								<?php esc_html_e( 'Pubblica / Aggiorna contenuto', 'db-privacy-hub' ); ?>
							</button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
							<input type="hidden" name="action" value="dbph_download_md">
							<?php wp_nonce_field( self::NONCE_DOWNLOAD, '_dbph_nonce' ); ?>
							<button type="submit" class="db-ui-btn">
								<?php esc_html_e( 'Scarica .md', 'db-privacy-hub' ); ?>
							</button>
						</form>
					</div>
				</div>

				<script>
				(function(){
					var sel = document.getElementById('dbph_target_page');
					var warn = document.getElementById('dbph-overwrite-warning');
					var form = document.getElementById('dbph-publish-form');
					if (!sel || !warn || !form) return;

					function refresh() {
						warn.style.display = (sel.value && sel.value !== 'new') ? '' : 'none';
					}
					sel.addEventListener('change', refresh);
					refresh();

					form.addEventListener('submit', function(e){
						if (sel.value && sel.value !== 'new') {
							var opt = sel.options[sel.selectedIndex];
							var title = opt.getAttribute('data-title') || ('ID ' + sel.value);
							var msg = <?php echo wp_json_encode( __( 'Stai per sovrascrivere il contenuto della pagina "%s". L\'operazione è reversibile dalle revisioni WordPress della pagina ma agisce immediatamente. Continuare?', 'db-privacy-hub' ) ); ?>;
							if (!window.confirm(msg.replace('%s', title))) {
								e.preventDefault();
								return false;
							}
						}
					});
				})();
				</script>

				<h2><?php esc_html_e( 'Anteprima', 'db-privacy-hub' ); ?></h2>
				<div class="db-ui-card">
					<div class="db-ui-card-body dbph-preview">
						<?php echo wp_kses_post( $preview_html ); ?>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Restituisce le pagine candidate ad ospitare la Privacy Policy.
		 * Heuristica: titoli che contengono "privacy", "informativa", "cookie",
		 * "gdpr", oppure la pagina già impostata come wp_page_for_privacy_policy
		 * di WordPress core. Esclude la pagina già collegata (passata in input).
		 *
		 * @param int $exclude_id ID da escludere dai candidati (es. la pagina già collegata).
		 * @return array<int,WP_Post>
		 */
		private static function get_candidate_pages( $exclude_id = 0 ) {
			$keywords = array( 'privacy', 'informativa', 'cookie', 'gdpr' );
			$ids = array();

			foreach ( $keywords as $kw ) {
				$matches = get_posts( array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 30,
					's'              => $kw,
					'fields'         => 'ids',
				) );
				foreach ( $matches as $id ) {
					$ids[ (int) $id ] = true;
				}
			}

			// Aggiungi anche la pagina core wp_page_for_privacy_policy se non già presente.
			$core_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
			if ( $core_id > 0 ) {
				$ids[ $core_id ] = true;
			}

			// Escludi la pagina passata e la pagina già collegata all'Hub.
			$dbph_id = (int) get_option( 'dbph_page_id', 0 );
			unset( $ids[ (int) $exclude_id ] );

			$out = array();
			foreach ( array_keys( $ids ) as $id ) {
				$post = get_post( $id );
				if ( $post && $post->post_type === 'page' ) {
					// Match sul titolo per essere sicuri (search WP cerca anche nel content).
					$title_lc = strtolower( (string) $post->post_title );
					$is_match = false;
					foreach ( $keywords as $kw ) {
						if ( strpos( $title_lc, $kw ) !== false ) {
							$is_match = true;
							break;
						}
					}
					// Includi sempre la pagina core privacy anche se non matcha le keyword.
					if ( $is_match || $post->ID === $core_id || $post->ID === $dbph_id ) {
						$out[] = $post;
					}
				}
			}

			// Ordina per titolo.
			usort( $out, function ( $a, $b ) {
				return strcasecmp( (string) $a->post_title, (string) $b->post_title );
			} );

			return $out;
		}

		/* =====================================================================
		 * Handler: Save titolare + page settings
		 * ================================================================== */

		public static function handle_save_titolare() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permesso negato.', 'db-privacy-hub' ), '', array( 'response' => 403 ) );
			}
			check_admin_referer( self::NONCE_SAVE, '_dbph_nonce' );

			$in = isset( $_POST['dbph'] ) && is_array( $_POST['dbph'] ) ? wp_unslash( $_POST['dbph'] ) : array();

			update_option( 'dbph_titolare_nome',      sanitize_text_field( $in['nome'] ?? '' ) );
			update_option( 'dbph_titolare_piva',      sanitize_text_field( $in['piva'] ?? '' ) );
			update_option( 'dbph_titolare_indirizzo', sanitize_text_field( $in['indirizzo'] ?? '' ) );

			$email = sanitize_email( $in['email'] ?? '' );
			update_option( 'dbph_titolare_email', is_email( $email ) ? $email : '' );

			$pec = sanitize_email( $in['pec'] ?? '' );
			update_option( 'dbph_titolare_pec', is_email( $pec ) ? $pec : '' );

			update_option( 'dbph_titolare_dpo',  sanitize_text_field( $in['dpo'] ?? '' ) );

			// Page settings.
			$title = sanitize_text_field( $in['page_title'] ?? '' );
			update_option( 'dbph_page_title', $title !== '' ? $title : __( 'Privacy Policy', 'db-privacy-hub' ) );

			$slug = sanitize_title( $in['page_slug'] ?? '' );
			update_option( 'dbph_page_slug', $slug !== '' ? $slug : 'privacy-policy' );

			DBPH_Register::flush_cache();

			$redirect = add_query_arg(
				array( 'page' => self::PAGE_GENERATOR, 'dbph_msg' => 'titolare_saved' ),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		/* =====================================================================
		 * Handler: Crea / aggiorna pagina WordPress
		 * ================================================================== */

		public static function handle_create_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permesso negato.', 'db-privacy-hub' ), '', array( 'response' => 403 ) );
			}
			check_admin_referer( self::NONCE_CREATE, '_dbph_nonce' );

			if ( ! DBPH_Policy_Generator::is_titolare_configured() ) {
				wp_safe_redirect( add_query_arg(
					array( 'page' => self::PAGE_GENERATOR, 'dbph_msg' => 'no_titolare' ),
					admin_url( 'admin.php' )
				) );
				exit;
			}

			$content = DBPH_Policy_Generator::generate();
			$title   = (string) get_option( 'dbph_page_title', __( 'Privacy Policy', 'db-privacy-hub' ) );
			$slug    = (string) get_option( 'dbph_page_slug', 'privacy-policy' );

			// Target dal dropdown: 'new' = crea nuova, intero positivo = sovrascrivi pagina esistente.
			$target_raw = isset( $_POST['dbph_target_page'] ) ? sanitize_text_field( wp_unslash( $_POST['dbph_target_page'] ) ) : 'new';
			$target_id  = ( $target_raw === 'new' ) ? 0 : (int) $target_raw;

			if ( $target_id > 0 ) {
				return self::do_overwrite_page( $target_id, $content );
			}

			return self::do_create_new_page( $title, $slug, $content );
		}

		/**
		 * Sovrascrive il contenuto di una pagina WordPress esistente con la
		 * Privacy Policy generata. Non tocca titolo né slug della pagina.
		 *
		 * Salva uno snapshot del contenuto pre-overwrite nell'archivio Hub
		 * (oltre alle revisioni native di WordPress che vengono create
		 * automaticamente da wp_update_post).
		 *
		 * @param int    $page_id ID della pagina da sovrascrivere.
		 * @param string $content Nuovo contenuto.
		 * @return void
		 */
		private static function do_overwrite_page( $page_id, $content ) {
			$page = get_post( $page_id );
			if ( ! $page || $page->post_type !== 'page' || $page->post_status === 'trash' ) {
				wp_safe_redirect( add_query_arg(
					array( 'page' => self::PAGE_GENERATOR, 'dbph_msg' => 'page_error' ),
					admin_url( 'admin.php' )
				) );
				exit;
			}

			// Snapshot del contenuto PRECEDENTE: utile come backup esplicito
			// nell'archivio Hub. Le revisioni WP coprono il post stesso, ma
			// se l'admin un domani cancella la pagina, le revisioni spariscono;
			// l'archivio Hub invece resta.
			if ( class_exists( 'DBPH_Policy_Archive' ) && trim( (string) $page->post_content ) !== '' ) {
				DBPH_Policy_Archive::save(
					(string) $page->post_content,
					sprintf(
						/* translators: 1: titolo pagina, 2: ID */
						__( 'Backup pre-sovrascrittura di "%1$s" (ID %2$d)', 'db-privacy-hub' ),
						$page->post_title,
						(int) $page->ID
					)
				);
			}

			$updated = wp_update_post(
				array(
					'ID'           => (int) $page_id,
					'post_content' => $content,
					'post_status'  => $page->post_status === 'publish' ? 'publish' : $page->post_status,
				),
				true
			);

			if ( is_wp_error( $updated ) || 0 === $updated ) {
				wp_safe_redirect( add_query_arg(
					array( 'page' => self::PAGE_GENERATOR, 'dbph_msg' => 'page_error' ),
					admin_url( 'admin.php' )
				) );
				exit;
			}

			// Lega l'Hub a questa pagina e impostala come privacy policy del sito.
			update_option( 'dbph_page_id', (int) $page_id );
			update_option( 'wp_page_for_privacy_policy', (int) $page_id );

			// Snapshot del contenuto NUOVO appena pubblicato.
			if ( class_exists( 'DBPH_Policy_Archive' ) ) {
				DBPH_Policy_Archive::save(
					$content,
					sprintf(
						/* translators: 1: titolo pagina, 2: ID */
						__( 'Pubblicazione su "%1$s" (ID %2$d)', 'db-privacy-hub' ),
						$page->post_title,
						(int) $page->ID
					)
				);
			}

			wp_safe_redirect( add_query_arg(
				array( 'page' => self::PAGE_GENERATOR, 'dbph_msg' => 'page_updated' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		/**
		 * Crea una nuova pagina WordPress con titolo/slug configurati.
		 *
		 * @param string $title
		 * @param string $slug
		 * @param string $content
		 * @return void
		 */
		private static function do_create_new_page( $title, $slug, $content ) {
			$new_id = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => $content,
					'post_type'    => 'page',
					'post_status'  => 'publish',
				),
				true
			);

			if ( is_wp_error( $new_id ) || 0 === $new_id ) {
				wp_safe_redirect( add_query_arg(
					array( 'page' => self::PAGE_GENERATOR, 'dbph_msg' => 'page_error' ),
					admin_url( 'admin.php' )
				) );
				exit;
			}

			update_option( 'dbph_page_id', (int) $new_id );
			update_option( 'wp_page_for_privacy_policy', (int) $new_id );

			if ( class_exists( 'DBPH_Policy_Archive' ) ) {
				DBPH_Policy_Archive::save( $content, __( 'Pubblicazione iniziale', 'db-privacy-hub' ) );
			}

			wp_safe_redirect( add_query_arg(
				array( 'page' => self::PAGE_GENERATOR, 'dbph_msg' => 'page_created' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		/* =====================================================================
		 * Handler: Download .md
		 * ================================================================== */

		public static function handle_download_md() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permesso negato.', 'db-privacy-hub' ), '', array( 'response' => 403 ) );
			}
			check_admin_referer( self::NONCE_DOWNLOAD, '_dbph_nonce' );

			$html = DBPH_Policy_Generator::generate();
			$md   = DBPH_Policy_Generator::html_to_markdown( $html );

			$filename = 'privacy-policy-' . sanitize_file_name( get_bloginfo( 'name' ) ) . '-' . date( 'Ymd-His' ) . '.md';
			nocache_headers();
			header( 'Content-Type: text/markdown; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			echo $md; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		/* =====================================================================
		 * Render + Handler: Responsabili esterni (art. 28 GDPR)
		 * ================================================================== */

		public static function render_responsabili() {
			$resp = DBPH_Responsabili::get_all();
			// Aggiungo sempre una riga vuota per nuovi inserimenti.
			$resp[] = array( 'id' => '', 'nome' => '', 'ruolo' => '', 'paese' => '', 'extra_ue' => false, 'garanzie' => '', 'dpa_url' => '', 'note' => '' );
			?>
			<div class="wrap db-ui-wrap">
				<h1><?php esc_html_e( 'Responsabili esterni del trattamento (art. 28 GDPR)', 'db-privacy-hub' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Dichiara qui i soggetti esterni con cui hai stipulato un contratto di nomina come responsabile del trattamento (DPA). Queste dichiarazioni hanno priorità sulla detection automatica nella sezione "Destinatari" della Privacy Policy.', 'db-privacy-hub' ); ?>
				</p>

				<div class="db-ui-alert db-ui-alert-info">
					<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
					<span><?php esc_html_e( 'Esempi tipici: provider di hosting, gestore email transazionale (es. Mailgun, SendGrid), agenzia esterna che gestisce il sito, fornitore di backup, eventuale CDN. Non vanno qui i meri "destinatari" che sono autonomi titolari (es. Google Analytics).', 'db-privacy-hub' ); ?></span>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="db-ui-form">
					<input type="hidden" name="action" value="dbph_save_responsabili">
					<?php wp_nonce_field( self::NONCE_RESP, '_dbph_nonce' ); ?>

					<?php foreach ( $resp as $i => $r ) : ?>
						<div class="db-ui-card" style="margin-bottom:16px">
							<div class="db-ui-card-body">
								<div class="db-ui-field">
									<label><?php esc_html_e( 'Nome / Ragione sociale', 'db-privacy-hub' ); ?></label>
									<input type="text" name="dbph_resp[<?php echo (int) $i; ?>][nome]" value="<?php echo esc_attr( $r['nome'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Es. Aruba S.p.A.', 'db-privacy-hub' ); ?>">
								</div>
								<div class="db-ui-field">
									<label><?php esc_html_e( 'Ruolo / servizio fornito', 'db-privacy-hub' ); ?></label>
									<input type="text" name="dbph_resp[<?php echo (int) $i; ?>][ruolo]" value="<?php echo esc_attr( $r['ruolo'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Es. Hosting, Email transazionale, CDN…', 'db-privacy-hub' ); ?>">
								</div>
								<div class="db-ui-field">
									<label><?php esc_html_e( 'Paese', 'db-privacy-hub' ); ?></label>
									<input type="text" name="dbph_resp[<?php echo (int) $i; ?>][paese]" value="<?php echo esc_attr( $r['paese'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Es. Italia, Germania, Stati Uniti', 'db-privacy-hub' ); ?>">
								</div>
								<div class="db-ui-field">
									<label>
										<input type="checkbox" name="dbph_resp[<?php echo (int) $i; ?>][extra_ue]" value="1" <?php checked( $r['extra_ue'] ); ?>>
										<?php esc_html_e( 'Trasferimento extra-UE', 'db-privacy-hub' ); ?>
									</label>
								</div>
								<div class="db-ui-field">
									<label><?php esc_html_e( 'Garanzie ex art. 46 GDPR (se extra-UE)', 'db-privacy-hub' ); ?></label>
									<input type="text" name="dbph_resp[<?php echo (int) $i; ?>][garanzie]" value="<?php echo esc_attr( $r['garanzie'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Es. SCC + DPF, BCR, decisione di adeguatezza', 'db-privacy-hub' ); ?>">
								</div>
								<div class="db-ui-field">
									<label><?php esc_html_e( 'URL del DPA pubblico (opzionale)', 'db-privacy-hub' ); ?></label>
									<input type="url" name="dbph_resp[<?php echo (int) $i; ?>][dpa_url]" value="<?php echo esc_attr( $r['dpa_url'] ); ?>" class="regular-text" placeholder="https://...">
								</div>
								<div class="db-ui-field">
									<label><?php esc_html_e( 'Note (opzionale)', 'db-privacy-hub' ); ?></label>
									<textarea name="dbph_resp[<?php echo (int) $i; ?>][note]" rows="2" class="large-text"><?php echo esc_textarea( $r['note'] ); ?></textarea>
								</div>
								<input type="hidden" name="dbph_resp[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $r['id'] ); ?>">
							</div>
						</div>
					<?php endforeach; ?>

					<p class="description"><?php esc_html_e( 'Lascia vuoto il campo "Nome" per cancellare una voce. Aggiungere una nuova voce: salva la pagina e ricaricala — verrà aggiunta una riga vuota in fondo.', 'db-privacy-hub' ); ?></p>

					<p>
						<button type="submit" class="db-ui-btn db-ui-btn-primary">
							<?php esc_html_e( 'Salva responsabili', 'db-privacy-hub' ); ?>
						</button>
					</p>
				</form>
			</div>
			<?php
		}

		public static function handle_save_responsabili() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permesso negato.', 'db-privacy-hub' ), '', array( 'response' => 403 ) );
			}
			check_admin_referer( self::NONCE_RESP, '_dbph_nonce' );

			$raw = isset( $_POST['dbph_resp'] ) && is_array( $_POST['dbph_resp'] ) ? wp_unslash( $_POST['dbph_resp'] ) : array();
			DBPH_Responsabili::save_all( $raw );

			wp_safe_redirect( add_query_arg(
				array( 'page' => self::PAGE_RESPONSABILI, 'dbph_msg' => 'responsabili_saved' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		/* =====================================================================
		 * Render: Storico richieste DSAR
		 * ================================================================== */

		public static function render_dsar_log() {
			$entries = DBPH_DSAR_Log::get_entries( 100 );
			$total   = DBPH_DSAR_Log::get_total_count();
			$stats   = DBPH_DSAR_Log::get_stats();
			?>
			<div class="wrap db-ui-wrap">
				<h1><?php esc_html_e( 'Storico richieste DSAR', 'db-privacy-hub' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Registro permanente delle richieste di accesso (art. 15 GDPR) e cancellazione (art. 17 GDPR) gestite tramite Strumenti → Esporta/Cancella dati personali. Le email sono mascherate; un hash SHA-256 permette la verifica senza esposizione.', 'db-privacy-hub' ); ?>
				</p>

				<?php if ( $total === 0 ) : ?>
					<div class="db-ui-alert db-ui-alert-info">
						<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
						<span><?php esc_html_e( 'Nessuna richiesta registrata. Le voci verranno aggiunte automaticamente quando un utente conferma una richiesta tramite WordPress Privacy Tools.', 'db-privacy-hub' ); ?></span>
					</div>
				<?php else : ?>
					<div class="db-ui-card">
						<div class="db-ui-card-body" style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px">
							<div><strong><?php echo (int) $stats['total']; ?></strong><br><small><?php esc_html_e( 'Totali', 'db-privacy-hub' ); ?></small></div>
							<div><strong><?php echo (int) $stats['export_done']; ?></strong><br><small><?php esc_html_e( 'Export completati', 'db-privacy-hub' ); ?></small></div>
							<div><strong><?php echo (int) $stats['export_pending']; ?></strong><br><small><?php esc_html_e( 'Export pending', 'db-privacy-hub' ); ?></small></div>
							<div><strong><?php echo (int) $stats['erase_done']; ?></strong><br><small><?php esc_html_e( 'Cancellazioni completate', 'db-privacy-hub' ); ?></small></div>
							<div><strong><?php echo (int) $stats['erase_pending']; ?></strong><br><small><?php esc_html_e( 'Cancellazioni pending', 'db-privacy-hub' ); ?></small></div>
						</div>
					</div>

					<table class="widefat striped" style="margin-top:16px">
						<thead>
							<tr>
								<th><?php esc_html_e( 'ID', 'db-privacy-hub' ); ?></th>
								<th><?php esc_html_e( 'Email', 'db-privacy-hub' ); ?></th>
								<th><?php esc_html_e( 'Tipo', 'db-privacy-hub' ); ?></th>
								<th><?php esc_html_e( 'Stato', 'db-privacy-hub' ); ?></th>
								<th><?php esc_html_e( 'Richiesta', 'db-privacy-hub' ); ?></th>
								<th><?php esc_html_e( 'Confermata', 'db-privacy-hub' ); ?></th>
								<th><?php esc_html_e( 'Completata', 'db-privacy-hub' ); ?></th>
								<th><?php esc_html_e( 'Hash email', 'db-privacy-hub' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $entries as $e ) : ?>
								<tr>
									<td>#<?php echo (int) $e->id; ?></td>
									<td><code><?php echo esc_html( $e->email_display ); ?></code></td>
									<td>
										<?php if ( $e->request_type === 'export' ) : ?>
											<span class="db-ui-badge db-ui-badge-info">📤 <?php esc_html_e( 'Export', 'db-privacy-hub' ); ?></span>
										<?php else : ?>
											<span class="db-ui-badge db-ui-badge-warning">🗑 <?php esc_html_e( 'Erase', 'db-privacy-hub' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										$badge_class = $e->status === 'completed' ? 'db-ui-badge-success' : 'db-ui-badge-muted';
										?>
										<span class="db-ui-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $e->status ); ?></span>
									</td>
									<td><?php echo esc_html( $e->requested_at ?: '—' ); ?></td>
									<td><?php echo esc_html( $e->confirmed_at ?: '—' ); ?></td>
									<td><?php echo esc_html( $e->completed_at ?: '—' ); ?></td>
									<td><code style="font-size:10px"><?php echo esc_html( substr( $e->email_hash, 0, 16 ) ); ?>…</code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}

		/* =====================================================================
		 * Render: Storico Privacy Policy (versionamento)
		 * ================================================================== */

		public static function render_policy_history() {
			$view_id = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
			$diff_a  = isset( $_GET['diff_a'] ) ? absint( $_GET['diff_a'] ) : 0;
			$diff_b  = isset( $_GET['diff_b'] ) ? absint( $_GET['diff_b'] ) : 0;

			$entries = DBPH_Policy_Archive::get_all( 50 );
			?>
			<div class="wrap db-ui-wrap">
				<h1><?php esc_html_e( 'Storico Privacy Policy', 'db-privacy-hub' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Ogni pubblicazione/rigenerazione della Privacy Policy salva uno snapshot. Permette di dimostrare quale versione era pubblicata in una certa data.', 'db-privacy-hub' ); ?>
				</p>

				<?php if ( $diff_a && $diff_b ) :
					$diff_html = DBPH_Policy_Archive::diff( $diff_a, $diff_b );
					?>
					<h2><?php esc_html_e( 'Differenze', 'db-privacy-hub' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_POLICY_HIST ) ); ?>" class="page-title-action"><?php esc_html_e( '← torna allo storico', 'db-privacy-hub' ); ?></a>
					</h2>
					<?php
					if ( $diff_html ) {
						echo wp_kses_post( $diff_html );
					} else {
						echo '<p>' . esc_html__( 'Nessuna differenza riscontrata o snapshot non trovato.', 'db-privacy-hub' ) . '</p>';
					}
				elseif ( $view_id ) :
					$snap = DBPH_Policy_Archive::get( $view_id );
					if ( $snap ) :
						?>
						<h2>
							<?php
							/* translators: %s: data versione */
							printf( esc_html__( 'Versione del %s', 'db-privacy-hub' ), esc_html( $snap->created_at ) );
							?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_POLICY_HIST ) ); ?>" class="page-title-action"><?php esc_html_e( '← torna allo storico', 'db-privacy-hub' ); ?></a>
						</h2>
						<div class="db-ui-card">
							<div class="db-ui-card-body dbph-preview" style="max-height:none">
								<?php echo wp_kses_post( $snap->content ); ?>
							</div>
						</div>
					<?php endif;
				else : ?>
					<?php if ( empty( $entries ) ) : ?>
						<div class="db-ui-alert db-ui-alert-info">
							<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
							<span><?php esc_html_e( 'Nessuna versione archiviata. La prima pubblicazione della Privacy Policy creerà il primo snapshot.', 'db-privacy-hub' ); ?></span>
						</div>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'ID', 'db-privacy-hub' ); ?></th>
									<th><?php esc_html_e( 'Data', 'db-privacy-hub' ); ?></th>
									<th><?php esc_html_e( 'Nota', 'db-privacy-hub' ); ?></th>
									<th><?php esc_html_e( 'Dimensione', 'db-privacy-hub' ); ?></th>
									<th><?php esc_html_e( 'Hash', 'db-privacy-hub' ); ?></th>
									<th><?php esc_html_e( 'Azioni', 'db-privacy-hub' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								// Per il diff con la precedente, devo guardare l'entry seguente nell'ordine inverso.
								$entry_count = count( $entries );
								foreach ( $entries as $idx => $e ) :
									$prev_id = isset( $entries[ $idx + 1 ] ) ? (int) $entries[ $idx + 1 ]->id : 0;
								?>
									<tr>
										<td>#<?php echo (int) $e->id; ?></td>
										<td><?php echo esc_html( $e->created_at ); ?></td>
										<td><?php echo esc_html( $e->note ?: '—' ); ?></td>
										<td><?php echo esc_html( size_format( (int) $e->bytes ) ); ?></td>
										<td><code style="font-size:10px"><?php echo esc_html( substr( $e->content_hash, 0, 12 ) ); ?>…</code></td>
										<td>
											<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_POLICY_HIST, 'view' => (int) $e->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Visualizza', 'db-privacy-hub' ); ?></a>
											<?php if ( $prev_id ) : ?>
												| <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_POLICY_HIST, 'diff_a' => $prev_id, 'diff_b' => (int) $e->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Diff vs precedente', 'db-privacy-hub' ); ?></a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
