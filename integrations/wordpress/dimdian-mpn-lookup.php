<?php
/**
 * Plugin Name: DIMDIAN Manufacturer Part Number Lookup
 * Description: Read-only public product lookup by the store's configured manufacturer part-number metadata key.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 */
declare(strict_types=1);

if (!defined('ABSPATH')) exit;

/**
 * Set this constant in wp-config.php to the exact product meta key used by DIMDIAN.
 * Example only: define('DIMDIAN_MPN_META_KEY', '_your_actual_mpn_meta_key');
 */
add_action('rest_api_init', static function (): void {
 register_rest_route('dimdian/v1','/products',[
  'methods'=>WP_REST_Server::READABLE,
  'permission_callback'=>'__return_true',
  'args'=>[
   'mpn'=>['required'=>true,'type'=>'string','sanitize_callback'=>'sanitize_text_field'],
   'per_page'=>['required'=>false,'type'=>'integer','default'=>10],
  ],
  'callback'=>static function (WP_REST_Request $request) {
   if (!function_exists('wc_get_product')) return new WP_Error('woocommerce_unavailable','WooCommerce is unavailable',['status'=>503]);
   $metaKey=defined('DIMDIAN_MPN_META_KEY')?(string)DIMDIAN_MPN_META_KEY:'';
   if ($metaKey===''||strlen($metaKey)>191) return new WP_Error('mpn_meta_key_not_configured','Configure DIMDIAN_MPN_META_KEY in wp-config.php',['status'=>501]);
   $mpn=trim((string)$request->get_param('mpn'));
   if ($mpn===''||strlen($mpn)>100) return new WP_Error('invalid_mpn','A valid manufacturer part number is required',['status'=>400]);
   $limit=max(1,min(10,(int)$request->get_param('per_page')));
   $ids=get_posts([
    'post_type'=>'product','post_status'=>'publish','fields'=>'ids','posts_per_page'=>$limit,
    'no_found_rows'=>true,'meta_query'=>[[
     'key'=>$metaKey,'value'=>$mpn,'compare'=>'=',
    ]],
   ]);
   $items=[];
   foreach($ids as $id){
    $product=wc_get_product($id);if(!$product||!$product->is_visible())continue;
    $imageId=$product->get_image_id();
    $imageUrl=$imageId?wp_get_attachment_image_url($imageId,'full'):false;
    $mpnValue=get_post_meta($product->get_id(),$metaKey,true);
    $mpn=is_scalar($mpnValue)?sanitize_text_field((string)$mpnValue):'';
    $items[]=[
     'id'=>$product->get_id(),'name'=>$product->get_name(),'sku'=>$product->get_sku(),'mpn'=>$mpn,
     'price'=>$product->get_price(),'regular_price'=>$product->get_regular_price(),
     'sale_price'=>$product->get_sale_price(),'stock_status'=>$product->get_stock_status(),
     'stock_quantity'=>$product->get_stock_quantity(),'permalink'=>get_permalink($product->get_id()),
     'images'=>$imageUrl?[['src'=>$imageUrl]]:[],
     'type'=>$product->get_type(),
    ];
   }
   return rest_ensure_response($items);
  },
 ]);
});
