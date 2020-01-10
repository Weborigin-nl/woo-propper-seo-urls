<?php
/**
 * Plugin Name: Permalink Manager for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/permalink-manager-for-woocommerce/
 * Description: Permalink Manager for WooCommerce
 * Version: 1.0.2
 * Author: BeRocket
 * Requires at least: 5.0
 * Author URI: https://woocommerce-permalink-manager.com/
 * Text Domain: permalink-manager-for-woocommerce
 */
define( "BWLM_file", __FILE__ );

class BeRocketLinkManager {
    private $options     = array();
    private $txn_options = array( 'product_cat' => '', 'product_tag' => '' );
    private $product_base;

    public $settings = 'wc_links';

    public function __construct() {
        $this->options = get_option( $this->settings );

        if ( $this->options[ 'category' ] ) {
            $this->txn_options[ 'product_cat' ] = $this->options[ 'category' ];
        }

        if ( $this->options[ 'tag' ] ) {
            $this->txn_options[ 'product_tag' ] = $this->options[ 'tag' ];
        }

        if ( is_admin() ) {
            add_action( 'current_screen', array( $this, 'register_permalink_option' ), 11 );
            add_filter( 'plugin_action_links_' . plugin_basename( BWLM_file ), array( $this, 'plugin_action_links' ) );
        } else {
            add_filter( 'post_type_link', array( $this, 'rewrite_products' ), 1, 2 );
            add_filter( 'term_link', array( $this, 'rewrite_terms' ), 0, 3 );

            if ( ! empty( $this->options[ 'product' ] ) ) {
                add_action( 'request', array( $this, 'request' ), 11 );
            }
        }

        add_filter( 'query_vars', array( $this, 'query_vars' ) );
        add_action( 'wp', array( $this, 'redirect_301' ) );
        add_filter( 'rewrite_rules_array', array( $this, 'rewrite_rules' ), 99 );

        foreach (
            array(
                'created_product_cat',
                'edited_product_cat',
                'delete_product_cat',
                'created_product_tag',
                'edited_product_tag',
                'delete_product_tag',
                'update_option_' . $this->settings
            ) as $action
        ) {
            add_action( $action, 'flush_rewrite_rules' );
        }

        register_deactivation_hook( BWLM_file, 'flush_rewrite_rules' );
        register_activation_hook( BWLM_file, 'flush_rewrite_rules' );
    }

    public function register_permalink_option() {
        $screen = get_current_screen();

        if ( $screen->id == 'options-permalink' ) {
            $this->save_permalink_option();
            $this->_register_permalink_option();
        }
    }

    public function _register_permalink_option() {
        add_settings_section( 'bwlm_permalinks', '', array( $this, 'permalink_settings_page' ), 'permalink' );
    }

    public function save_permalink_option() {
        if ( isset( $_POST[ $this->settings ] ) and current_user_can('manage_options') ) {
            $post = array( 'category' => '', 'product' => '', 'tag' => '' );
            if ( in_array( $_POST[ $this->settings ]['category'], array('slug', 'hierarchical') ) )
                $post['category'] = $_POST[ $this->settings ]['category'];
            if ( in_array( $_POST[ $this->settings ]['product'], array('slug', 'category_slug', 'hierarchical') ) )
                $post['product'] = $_POST[ $this->settings ]['product'];
            if ( 'slug' == $_POST[ $this->settings ]['tag'] )
                $post['tag'] = $_POST[ $this->settings ]['tag'];

            update_option( $this->settings, $post );

            $options = $post;

            if ( ( ! empty( $options[ 'product' ] ) or ! empty( $options[ 'category' ] ) ) and ( ! get_option( 'permalink_structure' ) ) ) {
                update_option( 'permalink_structure', '/%postname%/' );
            }

            if ( ! empty( $options[ 'product' ] ) ) {
                if ( $options[ 'product' ] == 'slug' ) {
                    $wc[ 'product_base' ] = 'product';
                } else {
                    $wc[ 'product_base' ] = '/shop/%product_cat%/';
                }

                update_option( 'woocommerce_permalinks', $wc );
            }
        }
    }

    public function permalink_settings_page() {
        $options = get_option( $this->settings );
        ?>
        <h2 class="bwlm" id="bwlm-settings"><?=__( 'Permalink Manager for WooCommerce', 'permalink-manager-for-woocommerce' )?></h2>
        <h3 class="bwlm"><?=__( 'Categories', 'permalink-manager-for-woocommerce' )?></h3>
        <table class="form-table bwlm">
            <tbody>
            <tr>
                <th><label><input
                            name="<?= $this->settings ?>[category]" <?= ( ! $this->get( $options, 'category' ) ? "checked='checked'" : '' ) ?>
                            type="radio" value=""> <?=__( 'Default', 'permalink-manager-for-woocommerce' )?></label></th>
                <td><?=__( 'Use WooCommerce configuration', 'permalink-manager-for-woocommerce' )?></td>
            </tr>
            <tr>
                <th><label><input
                            name="<?= $this->settings ?>[category]" <?= ( $this->get( $options, 'category' ) == 'slug' ? "checked='checked'" : '' ) ?>
                            type="radio" value="slug"> <?=__( 'Category', 'permalink-manager-for-woocommerce' )?></label></th>
                <td>
                    <?=__( 'Remove WooCommerce keyword from the url and leave category slug', 'permalink-manager-for-woocommerce' )?>
                    <br>
                    <code><?= home_url( '/category/' ) ?></code>
                </td>
            </tr>
            <tr>
                <th><label><input
                            name="<?= $this->settings ?>[category]" <?= ( $this->get( $options, 'category' ) == 'hierarchical' ? "checked='checked'" : '' ) ?>
                            type="radio" value="hierarchical"> <?=__( 'Category with parents', 'permalink-manager-for-woocommerce' )?></label></th>
                <td>
                    <?=__( 'Add category parents hierarchy', 'permalink-manager-for-woocommerce' )?>
                    <br>
                    <code><?= home_url( '/parent-category/category/' ) ?></code>
                </td>
            </tr>
            </tbody>
        </table>

        <h3 class="bwlm"><?=__( 'Products', 'permalink-manager-for-woocommerce' )?></h3>
        <table class="form-table bwlm">
            <tbody>
            <tr>
                <th><label><input
                            name="<?= $this->settings ?>[product]" <?= ( ! $this->get( $options, 'product' ) ? "checked='checked'" : '' ) ?>
                            type="radio" value=""> <?=__( 'Default', 'permalink-manager-for-woocommerce' )?></label></th>
                <td><?=__( 'Use WooCommerce configuration', 'permalink-manager-for-woocommerce' )?></td>
            </tr>
            <tr>
                <th><label><input
                            name="<?= $this->settings ?>[product]" <?= ( $this->get( $options, 'product' ) == 'slug' ? "checked='checked'" : '' ) ?>
                            type="radio" value="slug"> <?=__( 'Product', 'permalink-manager-for-woocommerce' )?></label></th>
                <td>
                    <?=__( 'Remove WooCommerce keyword from the url and leave product slug', 'permalink-manager-for-woocommerce' )?>
                    <br>
                    <code><?= home_url( '/product/' ) ?></code>
                </td>
            </tr>
            <tr>
                <th><label><input
                            name="<?= $this->settings ?>[product]" <?= ( $this->get( $options, 'product' ) == 'category_slug' ? "checked='checked'" : '' ) ?>
                            type="radio" value="category_slug"> <?=__( 'Category', 'permalink-manager-for-woocommerce' )?></label></th>
                <td>
                    <?=__( 'Change WooCommerce keyword to product\'s primary category and leave product slug', 'permalink-manager-for-woocommerce' )?>
                    <br>
                    <code><?= home_url( '/category/product/' ) ?></code>
                </td>
            </tr>
            <tr>
                <th><label><input
                            name="<?= $this->settings ?>[product]" <?= ( $this->get( $options, 'product' ) == 'hierarchical' ? "checked='checked'" : '' ) ?>
                            type="radio" value="hierarchical"> <?=__( 'Category with parents', 'permalink-manager-for-woocommerce' )?></label></th>
                <td>
                    <?=__( "Change WooCommerce keyword to product's primary category parents hierarchy and leave product slug", 'permalink-manager-for-woocommerce' )?>
                    <br>
                    <code><?= home_url( '/parent-category/category/product/' ) ?></code>
                </td>
            </tr>
            </tbody>
        </table>

        <h3 class="bwlm"><?=__( 'Tags', 'permalink-manager-for-woocommerce' )?></h3>
        <table class="form-table bwlm">
            <tbody>
            <tr>
                <th><label><input
                            name="<?= $this->settings ?>[tag]" <?= ( ! $this->get( $options, 'tag' ) ? "checked='checked'" : '' ) ?>
                            type="radio" value=""> <?=__( 'Default', 'permalink-manager-for-woocommerce' )?></label></th>
                <td><?=__( 'Use WooCommerce configuration', 'permalink-manager-for-woocommerce' )?></td>
            </tr>
            <tr>
                <th><label><input
                            name="<?= $this->settings ?>[tag]" <?= ( $this->get( $options, 'tag' ) == 'slug' ? "checked='checked'" : '' ) ?>
                            type="radio" value="slug"> <?=__( 'Tag', 'permalink-manager-for-woocommerce' )?></label></th>
                <td>
                    <?=__( 'Remove WooCommerce keyword from the url and leave tag slug', 'permalink-manager-for-woocommerce' )?>
                    <br>
                    <code><?= home_url( '/tag/' ) ?></code>
                </td>
            </tr>
            </tbody>
        </table>
        <style>
            h2.bwlm {
                margin: 40px 0 30px;
            }

            h3.bwlm {
                font-size: 1.2em;
                color: #4d4d4d;
                margin-bottom: 10px;
            }

            table.bwlm th,
            table.bwlm td {
                padding-top: 8px;
                padding-bottom: 8px;
            }

            table.bwlm code {
                margin-top: 2px;
                display: inline-block;
            }
        </style>
        <?php
    }

    public function get( $array = array(), $key = '' ) {
        return ( empty( $array[ $key ] ) ? '' : $array[ $key ] );
    }

    public function plugin_action_links( $links ) {
        $action_links = array(
            'settings' => '<a href="' . admin_url( 'options-permalink.php#bwlm-settings' ) .
                          '" title="' . __( 'View Plugin Settings', 'permalink-manager-for-woocommerce' ) . '">' .
                          __( 'Settings', 'permalink-manager-for-woocommerce' ) . '</a>',
        );

        return array_merge( $action_links, $links );
    }

    /* REWRITE PROCESS */

    private function product_base() {
        if ( is_null( $this->product_base ) ) {
            $permalink_structure = wc_get_permalink_structure();
            $this->product_base  = $permalink_structure[ 'product_rewrite_slug' ];
        }

        return $this->product_base;
    }

    private function post_parent_link( $permalink, $post, $hierarchical ) {
        if ( false === strpos( $permalink, '%product_cat%' ) ) {
            return $permalink;
        }
        $term = $this->product_category( $post );

        if ( $term ) {
            $slug      = $this->term_path( $term, $hierarchical );
            $permalink = str_replace( '%product_cat%', $slug, $permalink );
        }

        return $permalink;
    }

    private function product_category( $product ) {
        $term = false;

        if ( $this->has_seo_plugin() ) {
            $primary_term = yoast_get_primary_term_id( 'product_cat', $product->ID );
            $term         = get_term( $primary_term );
        }

        if ( ! $term instanceof \WP_Term ) {
            $term = $this->primary_term( $product );
        }

        return $term;
    }

    protected function has_seo_plugin() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists( 'is_plugin_active' ) && defined( 'WPSEO_BASENAME' ) && is_plugin_active( WPSEO_BASENAME ) && function_exists( 'yoast_get_primary_term_id' );
    }

    private function primary_term( $product ) {
        $terms = get_the_terms( $product->ID, 'product_cat' );
        if ( empty( $terms ) ) {
            return null;
        }

        if ( function_exists( 'wp_list_sort' ) ) {
            $terms = wp_list_sort( $terms, 'term_id', 'ASC' );
        } else {
            usort( $terms, '_usort_terms_by_ID' );
        }

        $category_object = apply_filters( 'wc_product_post_type_link_product_cat', $terms[ 0 ], $terms, $product );
        $category_object = get_term( $category_object, 'product_cat' );

        return $category_object;
    }

    private function term_path( $term, $hierarchical ) {
        $slug = urldecode( $term->slug );

        if ( $hierarchical && $term->parent ) {
            $ancestors = get_ancestors( $term->term_id, 'product_cat' );
            foreach ( $ancestors as $ancestor ) {
                $ancestor_object = get_term( $ancestor, 'product_cat' );
                $slug            = urldecode( $ancestor_object->slug ) . '/' . $slug;
            }
        }

        return $slug;
    }

    public function is_hierarchical( $type ) {
        return $type === 'hierarchical';
    }

    public function rewrite_products( $permalink, $post ) {
        if ( $post->post_type !== 'product' ) {
            return $permalink;
        }

        if ( ! get_option( 'permalink_structure' ) ) {
            return $permalink;
        }

        if ( empty( $this->options[ 'product' ] ) ) {
            return $permalink;
        }

        $product_base = $this->product_base();
        if ( strpos( $product_base, '%product_cat%' ) !== false ) {
            $product_base = str_replace( '%product_cat%', '', $product_base );
        }

        $product_base = '/' . trim( $product_base, '/' ) . '/';
        $link         = str_replace( $product_base, '/', $permalink );
        $link         = $this->post_parent_link( $link, $post, $this->is_hierarchical( $this->options[ 'product' ] ) );

        return $link;
    }

    public function rewrite_terms( $link, $term, $taxonomy ) {
        if ( empty( $this->txn_options[ $taxonomy ] ) ) {
            return $link;
        }

        $isHierarchical = $this->is_hierarchical( $this->txn_options[ $taxonomy ] );

        return home_url( user_trailingslashit( $this->term_path( $term, $isHierarchical ) ) );
    }

    public function rewrite_rules( $rules ) {
        if ( empty( $this->txn_options ) ) {
            return $rules;
        }

        wp_cache_flush();

        global $wp_rewrite;

        $feed      = '(' . trim( implode( '|', $wp_rewrite->feeds ) ) . ')';
        $new_rules = array();

        if ( isset( $GLOBALS[ 'sitepress' ] ) ) {
            $sitepress                 = $GLOBALS[ 'sitepress' ];
            $has_get_terms_args_filter = remove_filter( 'get_terms_args', array(
                $sitepress,
                'get_terms_args_filter'
            ) );
            $has_get_term_filter       = remove_filter( 'get_term', array( $sitepress, 'get_term_adjust_id' ), 1 );
            $has_terms_clauses_filter  = remove_filter( 'terms_clauses', array( $sitepress, 'terms_clauses' ) );
        }

        $berocket_filters = '';
        if ( class_exists( 'BeRocket_AAPF' ) ) {
            $berocket_filters_links = get_option( 'berocket_permalink_option' );
            $berocket_filters       = ( empty( $berocket_filters_links[ 'variable' ] ) ? '' : $berocket_filters_links[ 'variable' ] );
        }

        foreach ( $this->txn_options as $taxonomy => $option ) {

            if ( ! empty( $option ) ) {
                if ( $taxonomy == 'product_cat' ) {
                    $terms = get_categories( array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                    ) );
                } elseif ( $taxonomy == 'product_tag' ) {
                    $terms = get_terms( 'product_tag' );
                }

                $hierarchical = $this->is_hierarchical( $option );
                foreach ( $terms as $term ) {
                    $slug = $this->term_path( $term, $hierarchical );
                    if ( $berocket_filters ) {
                        $new_rules[ "{$slug}/{$berocket_filters}/(.*)/?\$" ] = 'index.php?' . $taxonomy . '=' . $term->slug . '&filters=$matches[1]&bwlm=' . $taxonomy;
                    }

                    $new_rules[ "{$slug}/?\$" ]                                             = 'index.php?' . $taxonomy . '=' . $term->slug . '&bwlm=' . $taxonomy;
                    $new_rules[ "{$slug}/embed/?\$" ]                                       = 'index.php?' . $taxonomy . '=' . $term->slug . '&embed=true&bwlm=' . $taxonomy;
                    $new_rules[ "{$slug}/{$wp_rewrite->feed_base}/{$feed}/?\$" ]            = 'index.php?' . $taxonomy . '=' . $term->slug . '&feed=$matches[1]&bwlm=' . $taxonomy;
                    $new_rules[ "{$slug}/{$feed}/?\$" ]                                     = 'index.php?' . $taxonomy . '=' . $term->slug . '&feed=$matches[1]&bwlm=' . $taxonomy;
                    $new_rules[ "{$slug}/{$wp_rewrite->pagination_base}/?([0-9]{1,})/?\$" ] = 'index.php?' . $taxonomy . '=' . $term->slug . '&paged=$matches[1]&bwlm=' . $taxonomy;
                }
            }
        }

        if ( isset( $sitepress ) ) {
            if ( ! empty( $has_terms_clauses_filter ) ) {
                add_filter( 'terms_clauses', array( $sitepress, 'terms_clauses' ), 10, 3 );
            }

            if ( ! empty( $has_get_term_filter ) ) {
                add_filter( 'get_term', array( $sitepress, 'get_term_adjust_id' ), 1, 1 );
            }

            if ( ! empty( $has_get_terms_args_filter ) ) {
                add_filter( 'get_terms_args', array( $sitepress, 'get_terms_args_filter' ), 10, 2 );
            }
        }

        return $new_rules + $rules;
    }

    public function request( $request ) {
        global $wp, $wpdb;
        $url = $wp->request;

        if ( ! empty( $url ) ) {
            $url     = explode( '/', $url );
            $slug    = array_pop( $url );
            $replace = array();

            if ( $slug === 'feed' ) {
                $replace[ 'feed' ] = $slug;
                $slug              = array_pop( $url );
            }

            if ( $slug === 'amp' ) {
                $replace[ 'amp' ] = $slug;
                $slug             = array_pop( $url );
            }

            $comments_position = strpos( $slug, 'comment-page-' );

            if ( $comments_position === 0 ) {
                $replace[ 'cpage' ] = substr( $slug, strlen( 'comment-page-' ) );
                $slug               = array_pop( $url );
            }

            $sql   = "SELECT COUNT(ID) as count_id FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s";
            $query = $wpdb->prepare( $sql, array( $slug, 'product' ) );
            $num   = intval( $wpdb->get_var( $query ) );

            if ( $num > 0 ) {
                if ( empty( $request[ 'product' ] ) ) {
                    global $bwlm;
                    $bwlm = 'product';
                }

                $replace[ 'page' ]      = '';
                $replace[ 'post_type' ] = 'product';
                $replace[ 'product' ]   = $slug;
                $replace[ 'name' ]      = $slug;

                return $replace;
            }

        }

        return $request;
    }

    public function query_vars( $vars ) {
        $vars[] = 'bwlm';

        return $vars;
    }

    public function redirect_301() {
        global $wp, $bwlm;

        if ( empty( $wp->query_vars[ 'bwlm' ] ) and empty( $bwlm ) and is_woocommerce() ) {
            if ( is_product_category() and $this->options[ 'category' ] ) {
                global $wp_query;
                $queried_object = $wp_query->get_queried_object();
                $url            = get_term_link( $queried_object->term_id, 'product_cat' );
            }

            if ( is_product_tag() and $this->options[ 'tag' ] ) {
                global $wp_query;
                $queried_object = $wp_query->get_queried_object();
                $url            = get_term_link( $queried_object->term_id, 'product_tag' );
            }

            if ( is_product() and $this->options[ 'product' ] ) {
                global $wp_query;
                $queried_object = $wp_query->get_queried_object();
                $url            = get_the_permalink( $queried_object->ID );
            }

            if ( ! empty( $url ) ) {
                wp_safe_redirect( $url, 301 );
                exit();
            }
        }
    }

}

new BeRocketLinkManager;
