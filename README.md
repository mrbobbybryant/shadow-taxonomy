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
		get_tax_office(),
		get_type_staff(),
		array(
			'label'         => __( 'Offices', 'graydon' ),
			'rewrite'       => false,
			'public'        => true,
			'show_tagcloud' => false,
			'hierarchical'  => true,
		)
	);
    // We will make our connection here in the next step.
});
```

### Step Two:
Use the Shadow Taxonomy Library API to create an association.
```php
\Shadow_Taxonomy\Core\create_relationship( get_type_office(), get_tax_office() );
```

