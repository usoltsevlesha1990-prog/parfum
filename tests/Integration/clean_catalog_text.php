<?php
declare(strict_types=1);
require dirname(__DIR__,2) . '/tools/clean_catalog_text.php';
$socket=getenv('DB_SOCKET') ?: '';
if(!str_starts_with($socket,'/workspace/.parfum-runtime/')) { throw new RuntimeException('This test requires the isolated local development socket'); }
$db=new PDO('mysql:unix_socket='.$socket.';dbname=parfum_place_dev;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$fields=[
 'brands'=>['name','short_note','description','meta_title','meta_description'],
 'products'=>['name','gender','fragrance_group','top_notes','heart_notes','base_notes','release_year','description','meta_title','meta_description'],
];
// Temporary tables shadow the fixture catalog only for this connection.
foreach($fields as $table=>$columns) {
 $definitions=implode(',',array_map(static fn($c)=>'`'.$c.'` TEXT NULL',$columns));
 $db->exec('CREATE TEMPORARY TABLE `'.$table.'` (id INT PRIMARY KEY,sku VARCHAR(150),'.$definitions.') ENGINE=InnoDB');
 $insert=$db->prepare('INSERT INTO `'.$table.'` (id,sku,name,description) VALUES (1,?,?,?)');
 $insert->execute(['unchanged-sku','  Test name  ',"<p>Роза</p>\n<p>&lt;b&gt;Уд&lt;/b&gt;</p>"]);
}
$directory=sys_get_temp_dir().'/parfum-cleanup-test-'.bin2hex(random_bytes(8));
try {
 $result=cleanCatalogText($db,$directory);
 if($result['updated_rows']!==2 || $result['updated_fields']!==4) { throw new RuntimeException('Unexpected update counts'); }
 foreach(array_keys($fields) as $table) {
  $row=$db->query('SELECT * FROM `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
  if($row['name']!=='Test name' || $row['description']!=='Роза Уд' || $row['sku']!=='unchanged-sku' || $row['meta_title']!==null) { throw new RuntimeException('Incorrect database update'); }
 }
 $files=glob($directory.'/*.json');
 if(count($files)!==1) { throw new RuntimeException('Backup missing'); }
 $backup=json_decode(file_get_contents($files[0]),true,512,JSON_THROW_ON_ERROR);
 if($backup['brands'][0]['description']!=="<p>Роза</p>\n<p>&lt;b&gt;Уд&lt;/b&gt;</p>") { throw new RuntimeException('Original content not backed up'); }
 $repeat=cleanCatalogText($db,$directory);
 if($repeat['updated_rows']!==0) { throw new RuntimeException('Cleanup is not idempotent'); }
 echo "PASS: SQL updates, original backup, identifier/NULL preservation and repeatability\n";
} finally {
 foreach(glob($directory.'/*') ?: [] as $file) { unlink($file); }
 if(is_dir($directory)) { rmdir($directory); }
}
