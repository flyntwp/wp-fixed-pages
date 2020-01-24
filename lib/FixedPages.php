<?php

namespace WPFixedPages;

use WPFixedPages\CustomPostType;
use Routes;

class FixedPages {
    protected $version = 0;
    protected $global = false;
    protected $name = null;
    protected $pages = [];
    protected $pagesByPath = [];

    protected $dbVersionKey = '';
    protected $dbVersion = 0;

    public function __construct($config, $cptOptions = []) {
        $this->version = $config['version'];
        $this->name    = $config['name'];

        new CustomPostType($this->name, $cptOptions);

        $this->pages       = $this->setPages($config['pages']);
        $this->pagesByPath = $this->mapPagesToPaths($this->pages);
        $this->setDb();

        $this->getDbVersion();

        $this->addHooks();

        $this->registerRoutes($this->pages);
    }

    private function setPages($pages, $parentPath = '') {
        return array_map(function ($page) use ($parentPath) {
            $page['path'] = $pagePath = (! empty($parentPath) ? $parentPath . '/' : '') . $page['name'];
            if ( ! empty($page['subpages'])) {
                $page['subpages'] = $this->setPages($page['subpages'],
                    $pagePath);
            }

            return $page;
        }, $pages);
    }

    private function mapPagesToPaths($pages) {
        $pagesByPath = [];
        foreach ($pages as $page) {
            if ( ! empty($page['subpages'])) {
                $pagesByPath = array_merge($pagesByPath,
                    $this->mapPagesToPaths($page['subpages']));
                unset($page['subpages']);
            }
            $pagesByPath[ $page['path'] ] = $page;
        }

        return $pagesByPath;
    }

    private function setDb() {
        $this->db   = $GLOBALS['wpdb'];
        $this->name = sanitize_key($this->name);

        // Maybe create database key
        if (empty($this->dbVersionKey)) {
            $this->dbVersionKey = "custom-pages_{$this->name}_version";
        }
    }

    private function getDbVersion() {
        $this->dbVersion = (true === $this->global)
            ? get_network_option(null, $this->dbVersionKey, false)
            : get_option($this->dbVersionKey, false);
    }

    private function addHooks() {
        // Add table to the global database object
        add_action('switch_blog', [$this, 'switchBlog']);
        add_action('admin_init', [$this, 'maybeUpgrade']);
        add_action('admin_menu', [$this, 'removePageAttributeMetaBox']);
        add_filter('wp_insert_post_data', [$this, 'forcePostPublish']);
    }

    private function registerRoutes($pages) {
        foreach ($pages as $pageConfig) {
            if ( ! empty($pageConfig['route'])) {
                Routes::map($pageConfig['route'],
                    function () use ($pageConfig) {
                        $page = get_page_by_path($pageConfig['path'], null,
                            $this->name);
                        if ( ! empty($page)) {
                            $queryVars = [
                                'p'         => $page->ID,
                                'post_type' => $this->name
                            ];
                            add_action('do_parse_request',
                                function () use ($queryVars) {
                                    global $wp;
                                    $wp->query_vars = $queryVars;

                                    return false;
                                }, 10, 1);
                            $pageTemplate = get_post_meta($page->ID,
                                '_wp_page_template');

                            if ( ! empty($pageTemplate) && is_array($pageTemplate)) {
                                $pageTemplate = $pageTemplate[0];
                            }
                        }
                        $pageTemplate = empty($pageTemplate) ? 'templates/index.php' : $pageTemplate;

                        if (empty($pageConfig['callback'])) {
                            Routes::load($pageTemplate);
                        } else {
                            $args = func_get_args();
                            array_unshift($args, $pageTemplate);
                            call_user_func_array($pageConfig['callback'],
                                $args);
                        }
                    });
            }
            if ( ! empty($pageConfig['subpages'])) {
                $this->registerRoutes($pageConfig['subpages']);
            }
        }
    }

    public function switchBlog($site_id = 0) {
        if (false === $this->global) {
            $this->dbVersion = get_blog_option($site_id, $this->dbVersionKey,
                false);
        }
    }

    public function maybeUpgrade() {
        if (version_compare((int) $this->dbVersion, (int) $this->version,
            '>=')) {
            return;
        }
        $newPageIds = $this->addPages($this->pages);
        $this->removeObsoletePages($newPageIds);
        $this->setDbVersion();
    }

    private function addPages($pages, $parentPath = '') {
        $pageIds = [];
        if ( ! empty($parentPath)) {
            $parentObject = get_page_by_path($parentPath, null, $this->name);
        }
        foreach ($pages as $page) {
            if ( ! (isset($page['hidden']) && $page['hidden'])) {
                $pageObject = get_page_by_path($page['path'], null,
                    $this->name);
                if (empty($pageObject)) {
                    $pageId = wp_insert_post([
                        'post_title'  => $page['title'],
                        'post_name'   => $page['name'],
                        'post_type'   => $this->name,
                        'post_status' => 'publish',
                        'post_parent' => isset($parentObject) ? $parentObject->ID : 0,
                    ]);
                } else {
                    $pageId = $pageObject->ID;
                    $this->updatePageData($pageObject, $page,
                        isset($parentObject) ? $parentObject->ID : 0);
                }
                $pageIds[] = $pageId;
                if ( ! empty($page['template'])) {
                    update_post_meta($pageId, '_wp_page_template',
                        $page['template']);
                } else {
                    delete_post_meta($pageId, '_wp_page_template');
                }
            }
            if ( ! empty($page['subpages'])) {
                $subpageIds = $this->addPages($page['subpages'], $page['path']);
                $pageIds    = array_merge($pageIds, $subpageIds);
            }
        }

        return $pageIds;
    }

    private function updatePageData($pageObject, $pageConfig, $parentId) {
        $fieldsToUpdate = [];
        if ($pageObject->post_title !== $pageConfig['title']) {
            $fieldsToUpdate['post_title'] = $pageConfig['title'];
        }
        if ($pageObject->post_parent !== $parentId) {
            $fieldsToUpdate['post_parent'] = $parentId;
        }
        if ( ! empty($fieldsToUpdate)) {
            $fieldsToUpdate['ID'] = $pageObject->ID;
            wp_update_post($fieldsToUpdate);
        }
    }

    private function removeObsoletePages($usedPageIds) {
        $args = [
            'post_type' => $this->name,
        ];
        if ( ! empty($usedPageIds)) {
            $args['post__not_in'] = $usedPageIds;
        }
        $posts = get_posts($args);
        foreach ($posts as $post) {
            wp_delete_post($post->ID, true);
        }
    }

    private function setDbVersion() {
        $this->dbVersion = $this->version;

        // Update the DB version
        (true === $this->global)
            ? update_network_option(null, $this->dbVersionKey, $this->version)
            : update_option($this->dbVersionKey, $this->version);
    }

    public function forcePostPublish($data) {
        if ($data['post_type'] === $this->name) {
            $data['post_status'] = 'publish';
        }

        return $data;
    }

    public function removePageAttributeMetaBox() {
        if (is_admin()) {
            remove_meta_box('pageparentdiv', 'custom-page', 'normal');
        }
    }

    public function getRoute($path, $params = []) {
        $page = $this->pagesByPath[ $path ] ?? [];
        if (isset($page['route'])) {
            return $this->resolveUrl($page['route'], $params);
        }
    }

    protected function resolveUrl($route, $params) {
        # TODO find a better solution, use AltoRouter#generate
        $url = home_url($route);
        if (preg_match_all('`\/(:([^\/]+))\/?`', $route, $matches,
            PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                list($block, $id, $param) = $match;
                if (isset($params[ $param ])) {
                    $url = str_replace($id, $params[ $param ], $url);
                } else {
                    $url = str_replace($id, '', $url);
                }
            }
        }

        return user_trailingslashit($url);
    }

    public function getConfigByPath($path) {
        return $this->pagesByPath[ $path ] ?? null;
    }

    public function getAllByPath() {
        return $this->pagesByPath;
    }
}
