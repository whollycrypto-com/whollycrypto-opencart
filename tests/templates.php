<?php
$root=getenv('WHOLLY_TEST_OPENCART_ROOT');
if (!$root || getenv('WHOLLY_TEST_DISPOSABLE')!=='yes') exit(2);
require $root.'/system/storage/vendor/autoload.php';
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__)),['autoescape'=>false,'strict_variables'=>false]);
$html=$twig->render('admin/view/template/payment/whollycrypto.twig',['api_url'=>'https://api.example.test','pay_url'=>'https://pay.example.test','project_id'=>'11111111-1111-4111-8111-111111111111','store_id'=>'22222222-2222-4222-8222-222222222222','api_key'=>'','ipn_secret'=>'','status'=>1,'order_statuses'=>[['order_status_id'=>1,'name'=>'<script>bad</script>']], 'pending_status_id'=>1]);
if (!str_contains($html,'value="https://api.example.test"') || !str_contains($html,'&lt;script&gt;bad') || !str_contains($html,'value="1" selected>Enabled')) throw new RuntimeException('Settings values/escaping failed');
$checkout=$twig->render('catalog/view/template/payment/whollycrypto.twig',['confirm_url'=>'https://shop.example.test/?route=extension/whollycrypto/payment/whollycrypto.confirm&language=en-gb','wholly_token'=>'fixture-token','text_description'=>'Pay safely','button_pay'=>'Continue','text_retry'=>'Retry']);
if (!str_contains($checkout,'fixture\\u002Dtoken') || !str_contains($checkout,'aria-busy')) throw new RuntimeException('Checkout rendering failed');
echo "Native Twig: settings values, status selection, HTML escaping and checkout rendering passed.\n";
