<?php

namespace WPFixedPages;

class CustomPostType
{
    protected $defaultOptions = [
        'label' => 'Custom Pages',
        'singular_label' => 'Custom Page',
        'description' => '',
        'public' => false,
        'has_archive' => false,
        'show_ui' => true,
        'show_in_nav_menus' => true,
        'show_in_rest' => false,
        'hierarchical' => true,
        'supports' => [
          'title',
          'revisions',
        ],
        'labels' => [
            'menu_name' => 'Custom Pages',
            'all_items' => 'All Custom Pages',
        ],
        'menu_icon' => 'dashicons-admin-page',
        'capability_type' => 'page',
        'capabilities' => [
            'create_posts' => false,
            'publish_posts' => false,
            'delete_posts' => false,
            'delete_published_posts' => false
        ],
        'map_meta_cap' => true
    ];

    public function __construct($name, $options = [])
    {
        return register_post_type($name, array_merge($this->defaultOptions, $options));
    }
}
