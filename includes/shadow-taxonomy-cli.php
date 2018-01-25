<?php
namespace Shadow_Taxonomy\CLI;

use Shadow_Taxonomy\Core as Core;

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
				$associated_term = Core\get_associated_term( $post->ID, $tax );
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
				$associated_post = Core\get_associated_post( $term, $cpt );

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
			\WP_CLI::success( esc_html__( 'Shadow Taxonomy is in sync, no action needed.' ) );
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
			$term_id = Core\get_associated_term_id( $post );

			if ( empty( $term_id ) ) {
				\WP_CLI::error( sprintf( 'Associated Shadow %s not found', $args[0] ) );
			} else {
				$test = 0;
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

	/**
	 * [--dry-run]
	 * : Allows you to see the number of shadow terms which need to be created or deleted.
	 *
	 * @subcommand sync-terms
	 */
	public function migrate_shadow_terms( $args, $assoc_args ) {
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
			$term_to_create = [];
			$posts_missing_metadata = [];
			foreach ( $posts->posts as $post ) {
				$terms = wp_get_object_terms( $post->ID, $tax );

				if ( empty( $terms ) ) {
					array_push( $term_to_create, $post );
				} else {
					$post_meta = get_post_meta( $post->ID, 'shadow_term_id', true );

					if ( empty( $post_meta ) ) {
						array_push(
								$posts_missing_metadata,
								[
										'term_id' => $terms[0]->term_id,
										'post_id' => $post->ID
								]
						);
					}
				}
			}

			$count = $count + count( $term_to_create );
		}

		/**
		 * Check for orphan shadow terms which are no longer needed.
		 */
		$terms = get_terms( $tax, [ 'hide_empty' => false ] );

		if ( ! empty( $terms ) ) {
			$terms_to_delete = [];
			$terms_missing_metadata = [];
			foreach ( $terms as $term ) {
				$post = new \WP_Query([
					'post_type'         =>  $cpt,
					'posts_per_page'    => 1,
					'post_status'       => 'publish',
					'tax_query'         => [
						[
							'taxonomy'      =>  $tax,
							'field'         =>  'id',
							'terms'         =>  $term->term_id
						]
					],
					'no_found_rows'     => true
				]);

				if ( empty( $post->posts ) || is_wp_error( $post ) ) {
					array_push( $terms_to_delete, $term );
				} else {
					$term_meta = get_term_meta( $term->term_id, 'shadow_post_id', true );

					if ( empty( $term_meta ) ) {
						array_push(
							$terms_missing_metadata,
							[
								'term_id' => $term->term_id,
								'post_id' => $post->posts[0]->ID
							]
						);
					}
				}
			}
			$count = $count + count( $terms_to_delete );
		}

		/**
		 * Output When Running a dry-run
		 */
		if ( $dry_run ) {
			$items = [];
			if ( isset( $term_to_create ) ) {
				array_push( $items, [ 'action' => 'Create', 'count' => count( $term_to_create ) ] );
			}

			if ( isset( $terms_to_delete ) ) {
				array_push( $items, [ 'action' => 'Delete', 'count' => count( $terms_to_delete ) ] );
			}

			if ( isset( $terms_missing_metadata ) ) {
				array_push( $items, [ 'action' => 'Missing Term Meta', 'count' => count( $terms_missing_metadata ) ] );
			}

			if ( isset( $posts_missing_metadata ) ) {
				array_push( $items, [ 'action' => 'Missing Post Meta', 'count' => count( $posts_missing_metadata ) ] );
			}

			\WP_CLI::warning( esc_html__( 'View the below table to see how many terms will be created or deleted.' ) );
			\WP_CLI\Utils\format_items( 'table', $items, array( 'action', 'count' ) );
			return;
		}

		if ( empty( $term_to_create ) &&
		empty( $terms_to_delete ) &&
		empty( $terms_missing_metadata ) &&
		empty( $posts_missing_metadata ) ) {
			\WP_CLI::success( esc_html__( 'Shadow Taxonomy is in sync, no action needed.' ) );
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
			if ( ! empty( $term_to_create ) ) {
				foreach ( $term_to_create as $post ) {
					$new_term = wp_insert_term( $post->post_title, $tax, [ 'slug' => $post->post_name ] );

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
			if ( ! empty( $terms_to_delete ) ) {
				foreach ( $terms_to_delete as $term ) {
					if ( $verbose ) {
						\WP_CLI::log( sprintf( 'Deleting Orphan Term: %s', esc_html( $term->name ) ) );
					}

					wp_delete_term( $term->term_id,  $tax );
				}
			}

			if ( ! empty( $terms_missing_metadata ) ) {
				foreach ( $terms_missing_metadata as $meta ) {
					update_term_meta( $meta[ 'term_id' ], 'shadow_post_id', $meta[ 'post_id' ] );
				}
			}

			if ( ! empty( $posts_missing_metadata ) ) {
				foreach ( $posts_missing_metadata as $meta ) {
					update_post_meta( $meta[ 'post_id' ], 'shadow_term_id', $meta[ 'term_id' ] );
				}
			}
		}

		\WP_CLI::success( sprintf( 'Process Complete. Successfully synced %d posts and terms', absint( $count ) ) );
	}

	public function deep_sync( $args, $assoc_args ) {
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
				$shadow_term = get_post_meta( $post->ID, 'shadow_term_id', true );

				if ( empty( $shadow_term ) ) {
					return $post;
				}

				$term = get_term_by( 'slug', $post->post_name, $tax );

				if ( empty( $term )  ) {
					return $post;
				}

			} );
			$count = $count + count( $term_to_create );
		}

		/**
		 * Output When Running a dry-run
		 */
		if ( $dry_run ) {
			$items = [];
			if ( isset( $term_to_create ) ) {
				array_push( $items, [ 'action' => 'create', 'count' => count( $term_to_create ) ] );
			}

			\WP_CLI::warning( esc_html__( 'View the below table to see how many terms will be created or deleted.' ) );
			\WP_CLI\Utils\format_items( 'table', $items, array( 'action', 'count' ) );
			return;
		}

		if ( 0 === $count ) {
			\WP_CLI::success( esc_html__( 'Shadow Taxonomy is in sync, no action needed.' ) );
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
		}

		\WP_CLI::success( sprintf( 'Process Complete. Successfully synced %d posts and terms', absint( $count ) ) );
	}

}