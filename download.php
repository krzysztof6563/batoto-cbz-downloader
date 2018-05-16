<?php
$tillLastChapter = false;
$keepFiles = false;
$convert = true;
$downloadQueue = array();
$skipDownload = false;

function consoleLog($message) {
  echo $message."\n";
}

//Usage download.php URL
function setupProgram() {
  global $argc, $argv, $downloadQueue, $keepFiles, $tillLastChapter, $convert, $skipDownload;
  for ($i=1;$i<$argc;$i++) {
    if (substr($argv[$i], 0 , 2) == "--") {
      switch ($argv[$i]) {
        case '--keep-files':
          $keepFiles = true;
          break;
        case '--till-last-chapter':
          $tillLastChapter = true;
          break;
        case '--no-convert':
          $convert = false;
          break;
        case '--skip-download':
          $skipDownload = true;
          break;

        default:
          consoleLog("Unknown option: $argv[$i]");
          die();
      }
    } else {
      array_push($downloadQueue, $argv[$i]);
    }
  }
}

function downloadChapter($url) {
  global $keepFiles, $tillLastChapter, $convert, $skipDownload;
  //Downloaing page
  $fp = fopen('file.html', 'w+');
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_FILE, $fp);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1");
  consoleLog("Downloading webpage");
  curl_exec($ch);
  if(curl_errno($ch)){
      throw new Exception(curl_error($ch));
      die();
  }
  $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  //Getting images URLs
  consoleLog("Getting JSON data");
  $html = file_get_contents('file.html');
  $posStart = strpos($html, "var images = ");
  $posEnd = strpos($html, "};", $posStart);
  $json = substr($html, $posStart+13, $posEnd-$posStart-12);

  //Getting title
  consoleLog("Getting name");
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
  if (!$skipDownload) {
    //Loop for downloading images
    consoleLog("Downloading images for $dirName");
    foreach ($file as $key) {
      consoleLog("Downloading file ".($count+1)."/".$imageCount);
      $key = trim($key);
      $saveTo = sprintf("%03d.jpg", $count);
      $fp = fopen($dirName."/".$saveTo, 'w+');
      if($fp === false){
        throw new Exception('Could not open: ' . $dirName."/".$saveTo);
      }
      $ch = curl_init($key);
      curl_setopt($ch, CURLOPT_FILE, $fp);
      curl_setopt($ch, CURLOPT_TIMEOUT, 600);
      curl_exec($ch);
      if(curl_errno($ch)){
        throw new Exception(curl_error($ch));
      }
      $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      $count++;
    }
  }

  //Creating archive
  if ($convert) {
    consoleLog("Creating archive...");
    $zip = new ZipArchive();
    $zip->open($dirName.".cbz", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addEmptyDir($dirName);
    //Getting files to add
    $fileTable = scandir($dirName);
    //Adding files to zip
    for ($i=2;$i<count($fileTable);$i++) {
      $zip->addFile($dirName."/".$fileTable[$i], $dirName."/".$fileTable[$i]);
    }
    consoleLog("Saved as ".$dirName.".cbz");
    $zip->close();
  } else {
    consoleLog("Skipping conversion to .cbz archive");
  }
  if (!$keepFiles) {
    //Getting files to add
    $fileTable = scandir($dirName);
    consoleLog("Cleaning up...");
    unlink("file.html");
    for ($i=2;$i<count($fileTable);$i++) {
      unlink($dirName."/".$fileTable[$i]);
    }
    rmdir($dirName);
  }
  consoleLog("Done");
  if ($tillLastChapter) {
    $chapterIdStart = strpos($html, 'var nextCha =');
    $chapterIdEnd = strpos($html, ';', $chapterIdStart);
    $chapterId = substr($html, $chapterIdStart, $chapterIdEnd-$chapterIdStart);
    if (strpos($chapterId, "= null") === false) {
      $chapterId = substr($chapterId, 14);
      $chapterId = json_decode($chapterId);
      downloadChapter("https://bato.to/chapter/".$chapterId->base->uniqueId);
    } else {
      consoleLog("Reached last chapter, stopping program");
    }
  }
}

setupProgram();
foreach ($downloadQueue as $downloadItem) {
  downloadChapter($downloadItem);
}




 ?>
