<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$auth=$_SERVER['HTTP_AUTHORIZATION']??'';
if(!hash_equals('Basic '.base64_encode('ci-woo-key:ci-woo-secret'),$auth)){http_response_code(401);echo json_encode(['code'=>'rest_not_logged_in']);exit;}
$products=[
 ['id'=>101,'name'=>'Toyota Camry 2.5L Alternator','sku'=>'ALT-CAM-25','price'=>'25000.00','regular_price'=>'28000.00','sale_price'=>'25000.00','stock_status'=>'instock','stock_quantity'=>4,'permalink'=>'http://127.0.0.1:8083/product/alt-cam-25/','images'=>[['src'=>'http://127.0.0.1:8083/images/alt-cam-25.jpg']],'type'=>'simple','status'=>'publish'],
 ['id'=>102,'name'=>'Honda Civic Brake Pad Set','sku'=>'BP-CIVIC-18','price'=>'12500.00','regular_price'=>'12500.00','sale_price'=>'','stock_status'=>'outofstock','stock_quantity'=>0,'permalink'=>'http://127.0.0.1:8083/product/bp-civic-18/','images'=>[],'type'=>'simple','status'=>'publish'],
];
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if($path==='/wp-json/dimdian/v1/products'){
 $mpn=(string)($_GET['mpn']??'');
 echo json_encode($mpn==='DENSO-ALT-25'?[$products[0]]:[]);exit;
}
if($path==='/wp-json/wc/v3/products'){
 $sku=(string)($_GET['sku']??'');$search=strtolower((string)($_GET['search']??''));
 $rows=array_values(array_filter($products,static fn($p)=>$sku!==''?strcasecmp($p['sku'],$sku)===0:($search===''||str_contains(strtolower($p['name']),$search))));
 echo json_encode($rows);exit;
}
if(preg_match('#^/wp-json/wc/v3/products/(\d+)$#',$path,$match)){
 foreach($products as $product)if($product['id']===(int)$match[1]){echo json_encode($product);exit;}
 http_response_code(404);echo json_encode(['code'=>'woocommerce_rest_product_invalid_id']);exit;
}
http_response_code(404);echo json_encode(['code'=>'rest_no_route']);
