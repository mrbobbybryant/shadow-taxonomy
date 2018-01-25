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

	if ( ! post_type_exists( $post_type ) ) {
		error_log( 'Failed to create shadow taxonomy. Post type does not exist.' );
	}

	if ( ! taxonomy_exists( $taxonomy ) ) {
		error_log( 'Failed to create shadow taxonomy. Taxonomy does not exist.' );
	}

	add_action( 'wp_insert_post', create_shadow_term( $post_type, $taxonomy ) );
	add_action( 'before_delete_post', delete_shadow_term( $taxonomy ) );
	add_action( 'create_' . $taxonomy, create_shadow_post( $post_type, $taxonomy ) );
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

		if ( ! isset( $_POST['post_title'] ) && ! isset( $_POST['post_name'] ) ) {
			return false;
		}

		if ( $_POST['post_type'] !== $post_type ) {
			return false;
		}

		if ( ! $term ) {
			create_shadow_taxonomy_term( $post_id, $taxonomy );
		}

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
 * Function creates a closure for the create_ taxonomy term creation hook, which handles creating an
 * associated post for the target post type.
 *
 * @param string $post_type Post Type Slug.
 * @param string $taxonomy Taxonomy Slug.
 *
 * @return Closure
 */
function create_shadow_post( $post_type, $taxonomy ) {
	return function( $term_id ) use ( $post_type, $taxonomy ) {
		$is_current_post_type = ( isset( $_POST['post_type'] ) && $_POST['post_type'] !== $post_type );
		$is_current_taxonomy  = ( isset( $_POST['action'] ) && str_replace( 'add-', '', $_POST['action'] !== $taxonomy ) );

		if ( ! $is_current_post_type && ! $is_current_taxonomy ) {
			return false;
		}
		$term = get_term( $term_id, $taxonomy );

		if ( is_wp_error( $term ) ) {
			return false;
		}

		$post = get_related_post_by_slug( $term, $post_type );

		if ( ! empty( $post ) ) {
			return false;
		}

		return create_shadow_post_type( $post_type, $term );
	};
}

/**
 * Function responsible for actually creating the shadow term and set the term meta to
 * create the association.
 *
 * @param int    $post_id Post ID Number.
 * @param string $taxonomy Taxonomy Term Name.
 *
 * @return bool|int false Term ID if created or false if an error occurred.
 */
function create_shadow_taxonomy_term( $post_id, $taxonomy ) {
	$new_term = wp_insert_term( $_POST['post_title'], $taxonomy, [ 'slug' => $_POST['post_name'] ] );

	if ( is_wp_error( $new_term ) ) {
		return false;
	}

	update_term_meta( $new_term['term_id'], 'shadow_post_id', $post_id );
	update_post_meta( $post_id, 'shadow_term_id', $new_term['term_id'] );

	return $new_term;
}

/**
 * Function creates a new post to match the passed in taxonomy term.
 *
 * @param string $post_type Post Type Slug.
 * @param string $term Taxonomy Term name.
 */
function create_shadow_post_type( $post_type, $term ) {
	$new_post = wp_insert_post([
		'post_type'   => $post_type,
		'post_title'  => $term->name,
		'post_name'   => $term->slug,
		'post_status' => 'publish',
	]);

	update_post_meta( $new_post, 'shadow_term_id', $term->term_id );
	update_term_meta( $term->term_id, 'shadow_post_id', $new_post );

	return $new_post;
}

/**
 * Function checks to see if the current term and its associated post have the same
 * title and slug. While we generally rely on term and post meta to track association,
 * its important that these two value stay synced.
 *
 * @param object $term The Term Object.
 * @param object $post The Post Object.
 *
 * @return bool Return true if a match is found, or false if no match is found.
 */
function term_already_in_sync( $term, $post ) {
	if ( $post->post_title === $term->name && $post->post_name === $term->slug ) {
		return true;
	}

	return false;
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
	if ( $term->name === $post->post_title && $term->slug === $post->post_name ) {
		return true;
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

	$args = [
		'p'                      => $post_id,
		'post_type'              => $post_type,
		'post_status'            => 'publish',
		'posts_per_page'         => 1,
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	];

	$posts = new \WP_Query( $args );

	if ( is_wp_error( $posts ) && ! $posts->have_posts() ) {
		return false;
	}

	return $posts->posts[0];
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
