<?php
namespace Shadow_Taxonomy\CLI;

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

\WP_CLI::add_command( 'shadow', __NAMESPACE__ . '\Shadow_Terms' );

class Shadow_Terms extends \WP_CLI_Command {

	/**
	 * Command will loop through all items in the provided post type and sync them to a shadow term. Function will
	 * also loop through all taxonomy terms and remove any orphan terms. Once this function is complete
	 * your taxonomy relations will be 100% in sync.
	 *
	 * ## OPTIONS
	 *
	 *--cpt=<post_type_name>
	 * : The custom post to sync
	 *
	 * --tax=<taxonomy_name>
	 * : The Shadow taxonomy name for the above post type.
	 *
	 * [--verbose]
	 * : Prints rows to the console as they're updated.
	 *
	 * [--dry-run]
	 * : Allows you to see the number of shadow terms which need to be created or deleted.
	 *
	 * @subcommand sync
	 */
	public function sync_shadow_terms( $args, $assoc_args ) {

		if ( ! post_type_exists( $assoc_args['cpt'] ) ) {
			\WP_CLI::error( esc_html__( 'The Post Type you provided does not exist.' ) );
		}

		if ( ! taxonomy_exists( $assoc_args['tax'] ) ) {
			\WP_CLI::error( esc_html__( 'The Taxonomy you provided does not exist.' ) );
		}

		$verbose = isset( $assoc_args[ 'verbose' ] );
		$dry_run = isset( $assoc_args[ 'dry-run' ] );
		$tax = $assoc_args['tax'];
		$cpt = $assoc_args['cpt'];
		$count = 0;

		/**
		 * Check for missing Shadow Taxonomy Terms.
		 */
		$args = [
			'post_type'         =>  $cpt,
			'post_status'       =>  'publish',
			'posts_per_page'    =>  500
		];

		$posts = new \WP_Query( $args );

		if ( is_wp_error( $posts ) ) {
			\WP_CLI::error( esc_html__( 'An error occurred while searching for posts.' ) );
		}

		if ( $posts->have_posts() ) {
			$term_to_create = array_filter( $posts->posts, function( $post ) use ( $tax ) {
				$associated_term = get_associated_term( $post->ID, $tax );
				if ( empty( $associated_term ) ) {
					return $post;
				}
			} );
			$count = $count + count( $term_to_create );
		}

		/**
		 * Check for orphan shadow terms which are no longer needed.
		 */
		$terms = get_terms( $tax, [ 'hide_empty' => false ] );

		if ( ! empty( $terms ) ) {
			$terms_to_delete = array_filter( $terms, function( $term ) use ( $cpt ) {
				$associated_post = get_associated_post( $term, $cpt );

				if ( empty( $associated_post ) ) {
					return $term;
				}
			} );
			$count = $count + count( $terms_to_delete );
		}

		/**
		 * Output When Running a dry-run
		 */
		if ( $dry_run ) {
			$items = [];
			if ( isset( $term_to_create ) ) {
				array_push( $items, [ 'action' => 'create', 'count' => count( $term_to_create ) ] );
			}

			if ( isset( $terms_to_delete ) ) {
				array_push( $items, [ 'action' => 'delete', 'count' => count( $terms_to_delete ) ] );
			}

			\WP_CLI::warning( esc_html__( 'View the below table to see how many terms will be created or deleted.' ) );
			\WP_CLI\Utils\format_items( 'table', $items, array( 'action', 'count' ) );
			return;
		}

		if ( 0 === $count ) {
			\WP_CLI::log( esc_html__( 'Shadow Taxonomy is in sync, no action needed.' ) );
			return;
		}

		/**
		 * Process Shadow Taxonomy Additions and Deletions.
		 */
		if ( ! $dry_run ) {
			\WP_CLI::log( sprintf( 'Processing %d posts...', absint( $posts->found_posts ) ) );

			/**
			 * Create Shadow Terms
			 */
			if ( isset( $term_to_create ) ) {
				foreach ( $term_to_create as $post ) {
					$new_term = wp_insert_term( $post->post_title, $assoc_args['tax'], [ 'slug' => $post->post_name ] );

					if ( is_wp_error( $new_term ) ) {
						\WP_CLI::error( $new_term );
					}

					if ( $verbose ) {
						\WP_CLI::log( sprintf( 'Created Term: %s', esc_html( $post->post_title ) ) );
					}

					update_term_meta( $new_term[ 'term_id' ], 'shadow_post_id', $post->ID );
					update_post_meta( $post->ID, 'shadow_term_id', $new_term[ 'term_id' ] );
				}
			}

			/**
			 * Delete Shadow Terms
			 */
			if ( isset( $terms_to_delete ) ) {
				foreach ( $terms_to_delete as $term ) {
					if ( $verbose ) {
						\WP_CLI::log( sprintf( 'Deleting Orphan Term: %s', esc_html( $term->name ) ) );
					}

					wp_delete_term( $term->term_id,  $assoc_args['tax'] );
				}
			}
		}

		\WP_CLI::success( sprintf( 'Process Complete. Successfully synced %d posts and terms', absint( $count ) ) );
	}

	/**
	 * Command will check if the input post or taxonomy has a valid associated shadow object. Function
	 * does not fix any issues, it simply tells you the status of the association.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : The type of data to check. Possible arguments are post_type or taxonomy
	 *
	 * --<id>=<int>
	 * : The ID of the post type or taxonomy term to validate.
	 *
	 * [--<tax>=<taxnomy name>]
	 * : If type equals taxonomy then you will also need to provide the taxonomy name here.
	 * @subcommand check
	 * @synopsis
	 */
	public function check_sync( $args, $assoc_args ) {

		if ( 'post_type' === $args[0] ) {
			$post = get_post( $assoc_args[ 'id' ] );
			$term_id = get_associated_term_id( $post );

			if ( empty( $term_id ) ) {
				\WP_CLI::error( sprintf( 'Associated Shadow %s not found', $args[0] ) );
			} else {
				\WP_CLI::success( sprintf( 'Shadow Taxonomy is in Sync', $args[0] ) );
			}
		}

		if ( 'taxonomy' === $args[0] ) {

			if ( ! isset( $assoc_args[ 'tax' ] ) || ! taxonomy_exists( $assoc_args[ 'tax' ] ) ) {
				\WP_CLI::error( esc_html__( 'Please provide a valid taxonomy.' ) );
			}

			$term = get_term_by( 'id', $assoc_args[ 'id' ], $assoc_args[ 'tax' ] );
			$post_id = get_associated_post_id( $term );

			if ( empty( $post_id ) ) {
				\WP_CLI::error( sprintf( 'Associated Shadow %s not found', $args[0] ) );
			} else {
				\WP_CLI::success( sprintf( 'Shadow Taxonomy is in Sync', $args[0] ) );
			}
		}

		\WP_CLI::error( sprintf( 'Type should be either post_type or taxonomy.' ) );
	}

}