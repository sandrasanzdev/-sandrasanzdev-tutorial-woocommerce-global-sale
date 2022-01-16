<?php
/**
 * Global sale option for all products in a WooCommerce shop.
 *
 * Creates a field in the admin panel to define a discount in percentage for all the
 * products in the store. Products that were already on sale will have their sale
 * price overwritten by the global sale price.
 */
namespace SSanzDev_WC_Global_Sale;

defined('ABSPATH') || exit;

/**
 * Create a tab called "Global sale" in WooCommerce > Settings > Products.
 *
 * @see WC_Settings_Page::get_sections()
 * 
 * @link https://woocommerce.com/document/adding-a-section-to-a-settings-tab/#section-2
 * 
 * @param  array $sections List of tabs in the WooCommerce product settings admin
 *                         page.
 * @return array List of tabs in the WooCommerce product settings admin page, with
 * our custom tab added. 
 */
function add_global_sale_tab( $sections )
{
    $sections['ssanzdev-wc-global-sale'] = __('Global sale', 'ssanzdev-wc-global-sale');    
    return $sections;
}

add_filter('woocommerce_get_sections_products', 'SSanzDev_WC_Global_Sale\add_global_sale_tab');

/**
 * Content to display inside WooCommerce > Settings > Products > Global sale.
 * 
 * We will display two fields: a checkbox that allows the shop manager to easily
 * activate and deactivate the discount, and another field to specify the percentage
 * of discount to apply to the product's price. 
 * 
 * There's no need to create a function to save our values, as WooCommerce takes
 * care of storing them in the wp_options table.
 * 
 * @see WC_Settings_Page::get_settings_for_section()
 * @see WC_Settings_API To see the available setting types in WooCommerce's settings
 * API.
 * 
 * @link https://woocommerce.com/document/adding-a-section-to-a-settings-tab/#section-3
 * @link https://woocommerce.com/document/settings-api/
 * 
 * @param array  $settings        Settings array, each item being an associative
 *                                array representing a setting.
 * @param string $current_section The id of the section to return settings for.
 *  
 * @return array Settings array, including our custom fields.
 */
function global_sale_tab_content( $settings, $current_section )
{
    
    // If current tab is "Global sale", add our custom options.
    if ('ssanzdev-wc-global-sale' === $current_section ) {
        
        // Title and description of the section
        $settings[] = array(
        'name' => __('Global sale', 'ssanzdev-wc-global-sale'),
        'type' => 'title',
        'desc' => __('Manage discounts for all the products in the store.', 'ssanzdev-wc-global-sale'),
        'id' => 'ssanzdev-wc-global-sale__title'
        );

        // Discount in percentage
        $settings[] = array(
        'name' => __('Discount in %', 'ssanzdev-wc-global-sale'),
        'type' => 'number',
        'id'   => 'ssanzdev-wc-global-sale__percentage',
        'css'  => 'width:50px;',
        'desc' => __('Percentage to discount from the price of all the products in the store.', 'ssanzdev-wc-global-sale'),
        'default' => 0,
        'custom_attributes' => array(
        'step' => 1,
        'min' => 0,
        'max' => 100,
        )
        );

        // Enable global sale
        $settings[] = array(
        'name'    => __('Enable', 'ssanzdev-wc-global-sale'),
        'desc'    => __('Apply the specified discount to all the products in the store.', 'ssanzdev-wc-global-sale'),
        'id'      => 'ssanzdev-wc-global-sale__enable',
        'std'     => 'no', // WooCommerce < 2.0
        'default' => 'no', // WooCommerce >= 2.0
        'type'    => 'checkbox'
        );                    
            
        // End section
        $settings[] = array(
        'type' => 'sectionend',
        'id' => 'ssanzdev-wc-global-sale__end',
        );
        
    } 
    
    return $settings;    
    
}

add_filter('woocommerce_get_settings_products', 'SSanzDev_WC_Global_Sale\global_sale_tab_content', 10, 2);

/**
 * Delete variable and grouped products' cache when saving WooCommerce's settings.
 * 
 * WooCommerce saves certain product data to transients, such as variation prices.
 * After changing the global sale settings, we need to purge the cache so the new
 * prices are properly reflected. 
 * 
 * @link https://developer.wordpress.org/apis/handbook/transients/
 * 
 * @see wc_delete_product_transients()
 * @see WC_Admin_Settings::save()
 */
function clear_product_cache()
{

    // Get a list of the variable and grouped product ids.
    // Set pagination to false for speed.
    $args = array(
        'type' => array( 'variable', 'grouped' ),
        'limit' => -1,
        'paginate' => false,
        'return' => 'ids'
    );

    $products = wc_get_products($args);
    
    // Delete transients for each of these products.
    foreach( $products as $product_id ) {
        wc_delete_product_transients($product_id);
    }

}
add_action('woocommerce_update_options_products', 'SSanzDev_WC_Global_Sale\clear_product_cache');

/**
 * Get the global discount percentage.
 *  
 * @return int Percentage to discount from the price of all the products in the
 * store. If empty or not valid, returns zero.
 */
function get_global_sale_discount_percentage()
{
    
    // Get discount value from database (or from WordPress' object cache after the
    // first query).
    $percentage = get_option('ssanzdev-wc-global-sale__percentage');

    // Transform value to integer.
    // Negative values are turned into positive values.
    // Decimals are rounded down.
    // Non-numeric strings turn to zero.
    $percentage = absint($percentage);

    // Make sure it returns a value between 0 and 100.
    if($percentage > 100 ) {
        $percentage = 100;
    }

    return $percentage;
}

/**
 * Check if the global sale is enabled.
 * 
 * For the global sale to be considered enabled, the "Enable" checkbox must be
 * checked and the discount percentage set to something greater than zero.
 *  
 * @return boolean Whether the global sale checkbox is enabled or not.
 */
function is_global_sale_enabled()
{
    // Get "Enable" checkbox value from database (or from WordPress' object cache
    // after the first query).
    $enabled = get_option('ssanzdev-wc-global-sale__enable');

    return 'yes' === $enabled && get_global_sale_discount_percentage() > 0;
}

/**
 * Check if a product is free or if its price is not set.
 * 
 * Helper function to skip global sale calculations in free products.
 * 
 * Because the get_regular_price() method returns an empty string in variable and
 * grouped products, we will use the get_price_html() method as inspiration for
 * obtaining the regular price in these types of products.
 * 
 * @see WC_Product_Variable::get_price_html()
 * @see WC_Product_Grouped::get_price_html()
 * 
 * @param WC_Product $product The product which price we want to check.
 * 
 * @return boolean Whether the product is free or not.
 */
function is_product_free( $product )
{
    
    // Variable products
    if($product->is_type('variable') ) {
        
        $prices = $product->get_variation_prices();
        
        if (isset($prices['regular_price']) && !empty($prices['regular_price']) ) {
            // Use the price of the most expensive variation as reference of the
            // variable product price.
            $price = end($prices['regular_price']);
        } else {
            $price = 0;
        }
    
        // Grouped products
    } elseif($product->is_type('grouped') ) {
        
        // Checking if we are supposed to display the price including taxes or
        // not in our shop.
        $tax_display_mode = get_option('woocommerce_tax_display_shop');
        // Initializing the array where we are going to store each child's
        // price.
        $child_prices = array();
        // Getting the group's children products.
        $children = array_filter(
            array_map('wc_get_product', $product->get_children()),
            'wc_products_array_filter_visible_grouped'
        );
        
        // For each product in the group
        foreach ( $children as $child ) {
            // Get the regular price of the child.
            $child_price = $child->get_regular_price();

            // Calculate the final child product's price depending on
            // whether we are supposed to display the price including taxes
            // or not.
            // The reason why we need to send the price as an argument to
            // the wc_get_price_including_tax() function is that, if we
            // leave it blank, it will use the product's default price for
            // its calculations, instead of the regular price.
            $args = array( 'price' => $child_price );

            if('incl' === $tax_display_mode ) {
                $child_prices[] = wc_get_price_including_tax($child, $args);
            } else {
                $child_prices[] = wc_get_price_excluding_tax($child, $args);
            }
        }

        if(!empty($child_prices) ) {
            // Use the price of the most expensive child product in the group as
            // reference of the grouped product price.
            $price = max($child_prices);
        } else {
            $price = 0;
        }
    
        // Other products
    } else {
        $price = $product->get_regular_price();
    }

    return (float) $price === 0;

}

/**
 * Check if global sale applies to a product.
 * 
 * If global sale is enabled and product isn't free, global discount applies.
 *  
 * @param WC_Product $product The product we want to check whether the global sale
 *                            applies or not.
 * 
 * @return boolean Whether global sale applies to the product or not.
 */
function global_sale_applies( $product )
{
    
    return is_global_sale_enabled() && !is_product_free($product);
    
}

/**
 * If global sale is enabled, flag every product on the store as being on sale.
 *  
 * @param boolean    $on_sale Whether a product is on sale or not.
 * @param WC_Product $product The product that may be on sale.
 * 
 * @return boolean Whether the product is on sale or not, after checking if global
 * sale applies to the product
 */
function flag_all_products_for_sale( $on_sale, $product )
{

    if(global_sale_applies($product) ) {
        $on_sale = true;
    }

    return $on_sale;
    
}

add_filter('woocommerce_product_is_on_sale', 'SSanzDev_WC_Global_Sale\flag_all_products_for_sale', 20, 2);


/**
 * Calculate sale price of a product after applying global sale.
 *  
 * @param float $price Price of a product before the discount.
 * 
 * @return float Price of a product after the discount.
 */
function calc_global_sale_price( $price )
{
    
    // Make sure the price is float for calculations.
    $price = ( float ) $price;
    
    // Make sure the price is greater than zero to avoid dividing by zero.
    if($price > 0 ) {
        $discount = get_global_sale_discount_percentage() * $price / 100;
        $sale_price = $price - $discount;
    } else {
        $sale_price = 0;
    }

    return $sale_price;
}

/**
 * If global sale is enabled, apply discount to every product's sale price.
 *  
 * The following code is applied whenever WooCommerce returns either the sale
 * price or the default price (which is the sale price in products on sale).
 * It's also applied when returning a variable product's variation price.
 * 
 * @see WC_Data::get_prop() This is where the filters are actually applied.
 * 
 * @param float      $price   Price of a product before applying the global sale
 *                            discount.
 * @param WC_Product $product From which price we want to deduct the global sale
 *                            discount.
 * 
 * @return float Price of a product after applying the global sale discount.
 */
function set_global_sale_price( $price, $product )
{
    
    if(global_sale_applies($product) ) {
        $price = calc_global_sale_price($product->get_regular_price());
    }
    
    return $price;
    
}

// Filters the current price of a product, whether on sale or not.
add_filter('woocommerce_product_get_price', 'SSanzDev_WC_Global_Sale\set_global_sale_price', 99, 2);
add_filter('woocommerce_product_variation_get_price', 'SSanzDev_WC_Global_Sale\set_global_sale_price', 99, 2);
// Filters the sale price of a product.
add_filter('woocommerce_product_get_sale_price', 'SSanzDev_WC_Global_Sale\set_global_sale_price', 99, 2);
add_filter('woocommerce_product_variation_get_price_sale', 'SSanzDev_WC_Global_Sale\set_global_sale_price', 99, 2);

/**
 * If global sale is enabled, apply discount when caching variation prices.
 * 
 * To optimize the calculation of price ranges in variable products, WooCommerce
 * stores variations' prices in a transient. The following code is applied whenever
 * WooCommerce is retrieving the sale and the default prices of a variation in order
 * to store them in cache.
 * 
 * Use the following query in your database to check which products prices are
 * currently cached:
 * SELECT * FROM `wp_options` WHERE `option_name` LIKE '_transient_wc_product_children_%';
 * 
 * @link https://developer.wordpress.org/apis/handbook/transients/
 * 
 * @see WC_Product_Variable_Data_Store_CPT::read_price_data() This is where the
 * variation's prices are first retrieved and stored, and the filters are applied.
 * 
 * @param float                $price     Price of a product before applying the global sale
 *                                        discount.
 * @param WC_Product_Variation $variation 
 *  
 * @return float Price of the variation after applying the global sale discount.
 */
function set_global_sale_price_variation( $price, $variation )
{

    if(global_sale_applies($variation) ) {
        $price = calc_global_sale_price($variation->get_regular_price('edit'));
    }

    return $price;    

}

// WooCommerce stores the variation prices in a transient.
// This filters prices before storing them in the transient.
add_filter('woocommerce_variation_prices_price', 'SSanzDev_WC_Global_Sale\set_global_sale_price_variation', 99, 2);
add_filter('woocommerce_variation_prices_sale_price', 'SSanzDev_WC_Global_Sale\set_global_sale_price_variation', 99, 2);