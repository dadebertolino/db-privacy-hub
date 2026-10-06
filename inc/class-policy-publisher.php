<?php
/**
 * DBPH_Policy_Publisher — Pubblicazione della Privacy Policy come pagina
 * WordPress e archiviazione delle versioni.
 *
 * Estratta dagli handler admin (1.8.0) perché sia verificabile senza il
 * redirect finale: gli handler in DBPH_Admin chiamano questi metodi e
 * traducono il risultato in un messaggio.
 *
 * @package DB_Privacy_Hub
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Policy_Publisher' ) ) {

	class DBPH_Policy_Publisher {

		/**
		 * Sovrascrive il contenuto di una pagina esistente (titolo e slug
		 * restano quelli della pagina) e la imposta come pagina privacy.
		 *
		 * Il contenuto precedente, se non vuoto, viene archiviato come
		 * backup: non diventa mai la versione corrente (bug 4, 1.8.0).
		 *
		 * @param int    $page_id
		 * @param string $content
		 * @return int|WP_Error ID della pagina.
		 */
		public static function overwrite( $page_id, $content ) {
			$page = get_post( (int) $page_id );
			if ( ! $page || $page->post_type !== 'page' || $page->post_status === 'trash' ) {
				return new WP_Error( 'dbph_invalid_page', __( 'Pagina di destinazione non valida.', 'db-privacy-hub' ) );
			}

			// Backup esplicito nell'archivio Hub: le revisioni WordPress
			// sparirebbero se un domani la pagina venisse cancellata. Non serve
			// quando la pagina contiene già un testo dell'Hub archiviato.
			if ( trim( (string) $page->post_content ) !== '' ) {
				DBPH_Policy_Archive::save_backup(
					(string) $page->post_content,
					sprintf(
						/* translators: 1: titolo pagina, 2: ID */
						__( 'Backup pre-sovrascrittura di "%1$s" (ID %2$d)', 'db-privacy-hub' ),
						$page->post_title,
						(int) $page->ID
					)
				);
			}

			DBPH_Policy_Archive::set_publishing( true );
			$updated = wp_update_post(
				array(
					'ID'           => (int) $page->ID,
					'post_content' => $content,
				),
				true
			);
			DBPH_Policy_Archive::set_publishing( false );

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			if ( ! $updated ) {
				return new WP_Error( 'dbph_update_failed', __( 'Aggiornamento della pagina non riuscito.', 'db-privacy-hub' ) );
			}

			self::link_page( (int) $page->ID );
			self::archive_published(
				(int) $page->ID,
				$content,
				sprintf(
					/* translators: 1: titolo pagina, 2: ID */
					__( 'Pubblicazione su "%1$s" (ID %2$d)', 'db-privacy-hub' ),
					$page->post_title,
					(int) $page->ID
				)
			);

			return (int) $page->ID;
		}

		/**
		 * Crea una nuova pagina pubblicata con titolo e slug configurati e la
		 * imposta come pagina privacy.
		 *
		 * @param string $title
		 * @param string $slug
		 * @param string $content
		 * @return int|WP_Error ID della pagina.
		 */
		public static function create( $title, $slug, $content ) {
			DBPH_Policy_Archive::set_publishing( true );
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
			DBPH_Policy_Archive::set_publishing( false );

			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			if ( ! $new_id ) {
				return new WP_Error( 'dbph_insert_failed', __( 'Creazione della pagina non riuscita.', 'db-privacy-hub' ) );
			}

			self::link_page( (int) $new_id );
			self::archive_published( (int) $new_id, $content, __( 'Pubblicazione iniziale', 'db-privacy-hub' ) );

			return (int) $new_id;
		}

		/**
		 * Pagina collegata all'Hub, se esiste ancora e non è nel cestino.
		 *
		 * @return WP_Post|null
		 */
		public static function get_linked_page() {
			$page_id = (int) get_option( 'dbph_page_id', 0 );
			$page    = $page_id > 0 ? get_post( $page_id ) : null;
			if ( ! $page || $page->post_type !== 'page' || $page->post_status === 'trash' ) {
				return null;
			}
			return $page;
		}

		private static function link_page( $page_id ) {
			update_option( 'dbph_page_id', $page_id );
			update_option( 'wp_page_for_privacy_policy', $page_id );
		}

		/**
		 * Archivia il post_content effettivamente salvato (kses può alterarlo
		 * per utenti senza unfiltered_html), non l'HTML generato.
		 */
		private static function archive_published( $page_id, $content, $note ) {
			$saved = get_post( $page_id );
			DBPH_Policy_Archive::save( $saved ? (string) $saved->post_content : $content, $note );
		}
	}
}
