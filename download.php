<?php
//Usage download.php URL

//Downloaing page
$fp = fopen('file.html', 'w+');
$ch = curl_init($argv[1]);
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1");
echo "Downloading webpage\n";
curl_exec($ch);
if(curl_errno($ch)){
    throw new Exception(curl_error($ch));
    die();
}
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

//Getting images URLs
echo "Getting JSON data\n";
$html = file_get_contents('file.html');
$posStart = strpos($html, "var images = ");
$posEnd = strpos($html, "};", $posStart);
$json = substr($html, $posStart+13, $posEnd-$posStart-12);

//Getting title
echo "Getting name\n";
$posNameStart = strpos($html, 'selected="true"');
$posNameEnd = strpos($html, "<", $posNameStart);
$dirName = substr($html, $posNameStart+16, $posNameEnd-$posNameStart-16);

//Decoding JSON and making directory
$file = json_decode($json, true);
$count = 0;
$imageCount = count($file);
if (!is_dir($dirName)) {
  mkdir($dirName);
}

//Loop for downloading images
echo "Downloading images\n";
foreach ($file as $key) {
  echo "Downloading file ".($count+1)."/".$imageCount."\n";
  $key = trim($key);
  $saveTo = sprintf("%03d.jpg", $count);
  $fp = fopen($dirName."/".$saveTo, 'w+');
  if($fp === false){
      throw new Exception('Could not open: ' . $dirName."/".$saveTo);
  }
  $ch = curl_init($key);
  curl_setopt($ch, CURLOPT_FILE, $fp);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  curl_exec($ch);
  if(curl_errno($ch)){
      throw new Exception(curl_error($ch));
  }
  $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $count++;
}

//Creating archive
echo "Creating archive...\n";
$zip = new ZipArchive();
$zip->open($dirName.".cbz", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addEmptyDir($dirName);
//Getting files to add
$fileTable = scandir($dirName);
//Adding files to zip
for ($i=2;$i<count($fileTable);$i++) {
  $zip->addFile($dirName."/".$fileTable[$i], $dirName."/".$fileTable[$i]);
}
$zip->close();
echo "Cleaning up...\n";
unlink("file.html");
for ($i=2;$i<count($fileTable);$i++) {
  unlink($dirName."/".$fileTable[$i]);
}
rmdir($dirName);
echo "Done";



 ?>
