<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/tools/round_catalog_prices.php';
$socket=getenv('DB_SOCKET') ?: '';
if(!str_starts_with($socket,'/workspace/.parfum-runtime/')) { throw new RuntimeException('Local test socket required'); }
$db=new PDO('mysql:unix_socket='.$socket.';dbname=parfum_place_dev','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('CREATE TEMPORARY TABLE product_prices (id INT PRIMARY KEY,product_id INT,volume_ml INT,price DECIMAL(10,2),promo_price DECIMAL(10,2),stock INT) ENGINE=InnoDB');
$db->exec('INSERT INTO product_prices VALUES (1,1,100,28790.40,1911.35,3),(2,1,5,10.00,NULL,5),(3,2,75,0.01,9.99,0),(4,2,10,NULL,NULL,1),(5,3,100,29.99,30.00,2)');
$private=sys_get_temp_dir().'/parfum-rounding-test-'.bin2hex(random_bytes(8));
try {
 $result=roundCatalogPricesDown($db,$private);
 $rows=$db->query('SELECT price,promo_price FROM product_prices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 $expected=[['price'=>'28790.00','promo_price'=>'1910.00'],['price'=>'10.00','promo_price'=>null],['price'=>'0.00','promo_price'=>'0.00'],['price'=>null,'promo_price'=>null],['price'=>'20.00','promo_price'=>'30.00']];
 if($rows!==$expected || !$result['verified'] || count(glob($private.'/backups/*.json'))!==1) { throw new RuntimeException('Incorrect rounding or missing backup'); }
 $again=roundCatalogPricesDown($db,$private);
 if(!($again['already_applied']??false) || $db->query('SELECT price FROM product_prices WHERE id=1')->fetchColumn()!=='28790.00') { throw new RuntimeException('Price changed on repeated run'); }
 echo "PASS: round down to tens, no extra 4% cut, NULL/exact multiples/zero boundaries, backup and repeat prevention\n";
} finally {
 foreach(glob($private.'/backups/*') ?: [] as $file) { unlink($file); }
 if(is_dir($private.'/backups')) { rmdir($private.'/backups'); }
 foreach(glob($private.'/*') ?: [] as $file) { unlink($file); }
 if(is_dir($private)) { rmdir($private); }
}
