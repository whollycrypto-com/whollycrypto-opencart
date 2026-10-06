<?php
/** Disposable OpenCart 4.1 fixture with real native models and MySQL storage. */
$root=getenv('WHOLLY_TEST_OPENCART_ROOT');
if (!$root || getenv('WHOLLY_TEST_DISPOSABLE')!=='yes') { fwrite(STDERR,"Disposable OpenCart fixture required.\n"); exit(2); }
require $root.'/config.php';
if (!str_contains(HTTP_SERVER,'shop.example.test')) throw new RuntimeException('Not a fixture domain');
define('HTTP_CATALOG',HTTP_SERVER);
require DIR_SYSTEM.'startup.php';
require DIR_STORAGE.'vendor/autoload.php';
$autoload=new Opencart\System\Engine\Autoloader();
$autoload->register('Opencart\System',DIR_SYSTEM);
$autoload->register('Opencart\Catalog',DIR_APPLICATION);
$autoload->register('Opencart\Admin',DIR_OPENCART.'admin/');
$autoload->register('Opencart\Catalog\Controller\Extension\Whollycrypto',DIR_EXTENSION.'whollycrypto/catalog/controller/');
$autoload->register('Opencart\Catalog\Model\Extension\Whollycrypto',DIR_EXTENSION.'whollycrypto/catalog/model/');
$autoload->register('Opencart\Admin\Controller\Extension\Whollycrypto',DIR_EXTENSION.'whollycrypto/admin/controller/');
require dirname(__DIR__).'/system/library/whollycrypto/lib/autoload.php';
use WhollyCrypto\OpenCart\Gateway;
use WhollyCrypto\OpenCart\Protocol;

function ensure($yes,$why) {if(!$yes)throw new RuntimeException($why);}
function control(array $values) { $c=curl_init(getenv('WHOLLY_TEST_API_ORIGIN').'/control');curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($values),CURLOPT_RETURNTRANSFER=>true]);ensure(curl_exec($c)!==false,'fixture TLS'); }
$r=new Opencart\System\Engine\Registry();$config=new Opencart\System\Engine\Config();$config->addPath(DIR_CONFIG);$config->load('default');$config->load('catalog');$config->set('application','Catalog');$r->set('config',$config);
$db=new Opencart\System\Library\DB('mysqli',DB_HOSTNAME,DB_USERNAME,DB_PASSWORD,DB_DATABASE,DB_PORT);$r->set('db',$db);
foreach($db->query('SELECT * FROM `'.DB_PREFIX.'setting` WHERE store_id=0')->rows as $row)$config->set($row['key'],$row['serialized']?json_decode($row['value'],true):$row['value']);
$config->set('config_store_id',0);$config->set('config_language_id',1);$config->set('config_language','en-gb');
$r->set('event',new Opencart\System\Engine\Event($r));$r->set('factory',new Opencart\System\Engine\Factory($r));$r->set('load',new Opencart\System\Engine\Loader($r));$r->set('cache',new Opencart\System\Library\Cache('file',3600));
$language=new Opencart\System\Library\Language('en-gb');$language->addPath(DIR_LANGUAGE);$language->load('default');$r->set('language',$language);
$r->set('currency',new Opencart\System\Library\Cart\Currency($r));
$request=new Opencart\System\Library\Request();$request->server['REQUEST_METHOD']='POST';$r->set('request',$request);$r->set('response',new Opencart\System\Library\Response());
$r->set('user',new class {function hasPermission($a,$b){return true;}});
$r->set('session',new class {public array $data=['user_token'=>'fixture-admin-token'];});
$r->set('log',new Opencart\System\Library\Log('wholly-test.log'));
$r->set('url',new Opencart\System\Library\Url(HTTP_SERVER));
$r->set('document',new Opencart\System\Library\Document());
$config->set('application','Admin');
$admin=new Opencart\Admin\Controller\Extension\Whollycrypto\Payment\Whollycrypto($r);$admin->install();
foreach($db->query("SELECT * FROM `".DB_PREFIX."setting` WHERE code='payment_whollycrypto'")->rows as $row)$config->set($row['key'],$row['value']);
foreach(['api_url'=>getenv('WHOLLY_TEST_API_ORIGIN'),'pay_url'=>'https://pay.example.test','project_id'=>'11111111-1111-4111-8111-111111111111','store_id'=>'22222222-2222-4222-8222-222222222222','api_key'=>str_repeat('a',40),'ipn_secret'=>str_repeat('b',40),'status'=>1] as $k=>$v)$config->set('payment_whollycrypto_'.$k,$v);
$config->set('application','Catalog');
$gateway=new Gateway($r);$repo=$gateway->repository();$r->get('load')->model('checkout/order');
function newOrder($r): int {
    $db=$r->get('db');$db->query("INSERT INTO `".DB_PREFIX."order` SET store_id=0,store_name='Fixture',store_url='https://shop.example.test/',customer_id=0,firstname='Fixture',lastname='Customer',email='test@example.invalid',payment_method='".$db->escape(json_encode(['name'=>'Wholly Crypto','code'=>'whollycrypto.whollycrypto']))."',shipping_method='{}',total=25,currency_code='EUR',currency_value=1.2,currency_id=2,language_id=1,language_code='en-gb',date_added=NOW(),date_modified=NOW()");
    $id=$db->getLastId();
    $db->query("INSERT INTO `".DB_PREFIX."product` SET model='Fixture',quantity=100,subtract=1,status=1,price=25,date_added=NOW(),date_modified=NOW(),date_available=CURDATE()");$product=$db->getLastId();
    $db->query("INSERT INTO `".DB_PREFIX."order_product` SET order_id=$id,product_id=$product,master_id=0,name='Fixture',model='Fixture',quantity=1,price=25,total=25,tax=0,reward=0");
    return $id;
}
$options=['ipn_url'=>'https://shop.example.test/ipn','redirect_url'=>'https://shop.example.test/return','cancel_url'=>'https://shop.example.test/return'];
$id=newOrder($r);$first=$gateway->checkout($id,$options);ensure($gateway->checkout($id,$options)===$first,'same checkout reused');$a=$repo->load($id);
ensure($a['payload']['amount']==='30','saved order currency conversion applied');
function notify($gateway,$a,$sequence,$status): array {
    $event='55555555-5555-4555-8555-555555555555';$p=['payload_version'=>2,'event_id'=>$event,'event_type'=>$status==='settled'?'invoice.settled':'payment.received','invoice_id'=>$a['invoice_id'],'project_id'=>$a['project_id'],'store_id'=>$a['store_id'],'order_id'=>$a['payload']['order_id'],'amount'=>$a['payload']['amount'],'currency'=>$a['payload']['currency'],'sequence'=>$sequence,'status'=>$status];
    $raw=json_encode($p);$now=time();return $gateway->synchronize($a['order_id'],$raw,['Wholly-Signature'=>'t='.$now.',v1='.hash_hmac('sha256',$now.'.'.$raw,str_repeat('b',40)),'Wholly-Event-Id'=>$event,'Wholly-Delivery-Id'=>'66666666-6666-4666-8666-666666666666']);
}
control(['status'=>'processing','amount_status'=>'partial','sequence'=>2]);notify($gateway,$a,2,'processing');
ensure((int)$r->get('model_checkout_order')->getOrder($id)['order_status_id']===(int)$config->get('payment_whollycrypto_pending_status_id'),'partial pending');
control(['status'=>'settled','amount_status'=>'paid','sequence'=>3]);notify($gateway,$a,3,'settled');notify($gateway,$a,3,'settled');notify($gateway,$a,2,'processing');
ensure((int)$r->get('model_checkout_order')->getOrder($id)['order_status_id']===(int)$config->get('payment_whollycrypto_paid_status_id'),'settled paid');
ensure((int)$db->query("SELECT COUNT(*) c FROM `".DB_PREFIX."order_history` WHERE order_id=$id AND order_status_id=".(int)$config->get('payment_whollycrypto_paid_status_id'))->row['c']===1,'one paid history');
ensure((int)$db->query("SELECT p.quantity FROM `".DB_PREFIX."product` p JOIN `".DB_PREFIX."order_product` op ON p.product_id=op.product_id WHERE op.order_id=$id")->row['quantity']===99,'stock deducted exactly once');
control(['status'=>'invalid','sequence'=>4]);$gateway->synchronize($id);ensure($repo->load($id)['applied']==='paid','reorg not double paid/reversed');
control(['status'=>'new','amount_status'=>'none','sequence'=>1]);$late=newOrder($r);$gateway->checkout($late,$options);control(['status'=>'expired','sequence'=>2]);$gateway->synchronize($late);control(['status'=>'settled','amount_status'=>'paid','sequence'=>3]);$gateway->synchronize($late);
ensure($repo->load($late)['applied']==='review','late after expiry review');
control(['status'=>'new','amount_status'=>'none','sequence'=>1,'http_status'=>503]);$timeout=newOrder($r);try{$gateway->checkout($timeout,$options);throw new LogicException('accepted outage');}catch(WhollyCrypto\OpenCart\Sdk\Exception\ApiException $e){}$key=$repo->load($timeout)['attempt_key'];
control(['http_status'=>200]);$gateway->checkout($timeout,$options);ensure($repo->load($timeout)['attempt_key']===$key,'lost response reuses persisted key');
$db->query("UPDATE `".DB_PREFIX."order` SET total=30 WHERE order_id=$timeout");control(['status'=>'settled','amount_status'=>'paid','sequence'=>3]);$gateway->synchronize($timeout);ensure($repo->load($timeout)['applied']==='review','edited total review');
$model=new Opencart\Catalog\Model\Extension\Whollycrypto\Payment\Whollycrypto($r);$r->set('cart',new class {function hasSubscription(){return false;}});
// Register the extension language path as startup/extension does in the actual storefront.
$language->addPath('extension/whollycrypto',DIR_EXTENSION.'whollycrypto/catalog/language/');
ensure($model->getMethods()['option']['whollycrypto']['code']==='whollycrypto.whollycrypto' && $model->getMethods()['name']==='Wholly Crypto','payment discovery');
$normalProcessing=$config->get('config_processing_status');
$config->set('config_processing_status',array_merge((array)$normalProcessing,[(int)$config->get('payment_whollycrypto_pending_status_id')]));
$guarded=newOrder($r);
try { $gateway->checkout($guarded,$options); throw new LogicException('Unsafe status accepted'); } catch (RuntimeException $e) { ensure(!($e instanceof LogicException),'unsafe status refused'); }
ensure($repo->load($guarded)===null,'unsafe status cannot create payment');
$config->set('config_processing_status',$normalProcessing);
echo "OpenCart 4.1: native install/models/MySQL order history, real TLS, create/retry, partial/settled/duplicate/old/reorg/expired/changed-total and payment discovery passed.\n";
