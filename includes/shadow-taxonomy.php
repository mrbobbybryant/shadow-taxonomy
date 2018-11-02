<?php
namespace Shadow_Taxonomy\Core;

/**
 * Function registers a post to taxonomy relationship. Henceforth known as a shadow taxonomy. This function
 * hooks into the WordPress Plugins API and registers multiple hooks. These hooks ensure that any changes
 * made on the post type side or taxonomy side of a given relationship will stay in sync.
 *
 * @param string $post_type Post Type slug.
 * @param string $taxonomy Taxonomy Slug.
 */
function create_relationship( $post_type, $taxonomy ) {
	add_action( 'wp_insert_post', create_shadow_term( $post_type, $taxonomy ) );
	add_action( 'before_delete_post', delete_shadow_term( $taxonomy ) );
}

/**
 * Function creates a closure for the wp_insert_post hook, which handles creating an
 * associated taxonomy term.
 * @param string $post_type Post Type Slug
 * @param string $taxonomy Taxonomy Slug
 *
 * @return Closure
 */
function create_shadow_term( $post_type, $taxonomy ) {
	return function( $post_id ) use ( $post_type, $taxonomy ) {
		$term = get_associated_term( $post_id, $taxonomy );

		$post = get_post( $post_id );

		if ( $post->post_type !== $post_type ) {
			return false;
		}

		if ( 'auto-draft' === $post->post_status ) {
			return false;
		}

		if ( ! $term ) {
			create_shadow_taxonomy_term( $post_id, $post, $taxonomy );
		} else {
			$post = get_associated_post( $term, $post_type );

			if ( empty( $post ) ) {
				return false;
			}

			if ( post_type_already_in_sync( $term, $post ) ) {
				return false;
			}

			wp_update_term(
				$term->term_id,
				$taxonomy,
				[
					'name' => $post->post_title,
					'slug' => $post->post_name,
				]
			);
		}
	};

}

/**
 * Function creates a closure for the before_delete_post hook, which handles deleting an
 * associated taxonomy term.
 *
 * @param string $taxonomy Taxonomy Slug.
 *
 * @return Closure
 */
function delete_shadow_term( $taxonomy ) {
	return function( $postid ) use ( $taxonomy ) {
		$term = get_associated_term( $postid, $taxonomy );

		if ( ! $term ) {
			return false;
		}

		wp_delete_term( $term->term_id, $taxonomy );
	};
}

/**
 * Function responsible for actually creating the shadow term and set the term meta to
 * create the association.
 *
 * @param int    $post_id Post ID Number.
 * @param object $post The WP Post Object.
 * @param string $taxonomy Taxonomy Term Name.
 *
 * @return bool|int false Term ID if created or false if an error occurred.
 */
function create_shadow_taxonomy_term( $post_id, $post, $taxonomy ) {
	$new_term = wp_insert_term( $post->post_title, $taxonomy, [ 'slug' => $post->post_name ] );

	if ( is_wp_error( $new_term ) ) {
		return false;
	}

	update_term_meta( $new_term['term_id'], 'shadow_post_id', $post_id );
	update_post_meta( $post_id, 'shadow_term_id', $new_term['term_id'] );

	return $new_term;
}

/**
 * Function checks to see if the current term and its associated post have the same
 * title and slug. While we generally rely on term and post meta to track association,
 * its important that these two value stay synced.
 *
 * @param object $term The Term Object.
 * @param object $post The $_POST array.
 *
 * @return bool Return true if a match is found, or false if no match is found.
 */
function post_type_already_in_sync( $term, $post ) {
	if ( isset( $term->slug ) && isset( $post->post_name ) ) {
		if ( $term->name === $post->post_title && $term->slug === $post->post_name ) {
			return true;
		}
	} else {
		if ( $term->name === $post->post_title ) {
			return true;
		}
	}

	return false;
}

/**
 * Function finds the associated shadow post for a given term slug. This function is required due
 * to some possible recursion issues if we only check for posts by ID.
 *
 * @param object $term The Term Object.
 * @param string $post_type The Post Type Slug.
 *
 * @return bool|object Returns false if no post is found, or the Post Object if one is found.
 */
function get_related_post_by_slug( $term, $post_type ) {
	$post = new \WP_Query([
		'post_type'      => $post_type,
		'posts_per_page' => 1,
		'post_status'    => 'publish',
		'name'           => $term->slug,
		'no_found_rows'  => true,
	]);

	if ( empty( $post->posts ) || is_wp_error( $post ) ) {
		return false;
	}

	return $post->posts[0];
}

/**
 * Function gets the associated shadow post of a given term object.
 *
 * @param object $term WP Term Object.
 *
 * @return bool | int return the post_id or false if no associated post is found.
 */
function get_associated_post_id( $term ) {
	return get_term_meta( $term->term_id, 'shadow_post_id', true );
}

/**
 * Find the shadow or associted post to the input taxonomy term.
 *
 * @param object $term WP Term Objct.
 * @param string $post_type Post Type Name.
 *
 * @return bool|object Returns the associated post object or false if no post is found.
 */
function get_associated_post( $term, $post_type ) {

	if ( empty( $term ) ) {
		return false;
	}

	$post_id = get_associated_post_id( $term );

	if ( empty( $post_id ) ) {
		return false;
	}

	return get_post( $post_id );
}

/**
 * Function gets the associated shadow term of a given post object
 *
 * @param object $post WP Post Object.
 *
 * @return bool | int returns the term_id or false if no associated term was found.
 */
function get_associated_term_id( $post ) {
	return get_post_meta( $post->ID, 'shadow_term_id', true );
}

/**
 * Function gets the associated Term object for a given input Post Object.
 *
 * @param object|int $post WP Post Object or Post ID.
 * @param string     $taxonomy Taxonomy Name.
 *
 * @return bool|object Returns the associated term object or false if no term is found.
 */
function get_associated_term( $post, $taxonomy ) {

	if ( is_int( $post ) ) {
		$post = get_post( $post );
	}

	if ( empty( $post ) ) {
		return false;
	}

	$term_id = get_associated_term_id( $post );
	return get_term_by( 'id', $term_id, $taxonomy );
}

/**
 * Function will get all related posts for a given post ID. The function
 * essentially converts all the attached shadow term relations into the actual associated
 * posts.
 *
 * @param int    $post_id The ID of the post.
 * @param string $taxonomy The name of the shadow taxonomy.
 * @param string $cpt The name of the associated post type.
 *
 * @return array|bool Returns false or an are of post Objects if any are found.
 */
function get_the_posts( $post_id, $taxonomy, $cpt ) {
	$terms = get_the_terms( $post_id, $taxonomy );

	if ( ! empty( $terms ) ) {
		return array_map( function( $term ) use ( $cpt ) {
			$post = get_associated_post( $term, $cpt );
			if ( ! empty( $post ) ) {
				return $post;
			}
		}, $terms );
	}
	return false;
}
