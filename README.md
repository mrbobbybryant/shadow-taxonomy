# Shadow Taxonomy
Useful for relating Post Types to other Post Types.

### Introduction
One of the hardest things to do in WordPress is creating relationships between two different post types. Often times
this is accomplished by saving information about the relationships in post meta. However this leads to your code having
a number of meta queries, and meta queries are generally one of the pooest most taxing queries you can make in WordPress.

Metadata can also be a pain to keep synced. For example, when posts are deleted, what happens to the post meta you have
saved in on a seperate post type?

## What is a Shadow Taxonomy.
A shadow taxonomy is a custom WordPress taxonomy which is created to mirror a specific post type. So anytime post in that
post type is created, updated, or deleted, the associated shadow taxonomy term is also created, updated, and deleted.

Additionally by using a taxonomy we get a nice UI of checkboxes for linking posts together for free. 

## Useage

### Step One:
Create the Shadow Taxonomy.
```php
add_action( 'init', function() {
	register_taxonomy(
		'services-tax',
		'staff-cpt',
		array(
			'label'         => __( 'Services', 'text-domain' ),
			'rewrite'       => false,
			'show_tagcloud' => false,
			'hierarchical'  => true,
		)
	);
    // We will make our connection here in the next step.
});
```
Here we are simple creating a normal custom taxonomy. In our example we are creating a taxonomy to mirror a CPT we already
have completed called Services. So as a convention I have named my Shadow Taxonomy 'services-tax'.

Also because I am wanting to link Services to another post type called Staff. I have registered this custom taxonomy to show up on the Staff CPT post edit screen.

Lastly, I have not made this taxonomy ```public```. That is because I don't want anybody in there messing with the terms for this taxonomy. I what to let the Shadow Taxonomy Library handle creating, updating, and deleting the shadow taxonomy terms. That way I can ensure that my shadow taxonomy stays properly syned to it's associated post type.

### Step Two:
Use the Shadow Taxonomy Library API to create an association.
```php
\Shadow_Taxonomy\Core\create_relationship( 'service-cpt', 'service-tax' );
```
This one line is all you need to create the shadow taxonomy link, so that this library can kick in and take over management
of the shadow taxonomy. The first argument is the custom post type name, and the second argument is the newly created shadow taxonomy
name.

This line should go immediately after the ```register_taxonomy``` call in the first step.
