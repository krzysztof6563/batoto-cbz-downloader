<?php
 /*
 * Author: Krzysztof Michalski
 * Github URL: https://github.com/krzysztof6563/
 *
 * An application that downloads chapters of manga from bato.to.
 * It has an ability to download till the end of current series
 *
 * Distribted under GNU GENERAL PUBLIC LICENSE v3 (See LICENSE file)
 *
 * Usage:
 * php downloader.php [URLs] {options}
 *
 * Options:
 * --no-convert - prevents packing-up to .cbz archive
 * --keep-files - prevents automatic deletion of downloaded files
 * --till-last-chapter - continues to download next chapters until the end of a current series
 * --skip-download - skips downloading files (usefull in debugging)
*/

//Global variables
$tillLastChapter = false;
$keepFiles = false;
$convert = true;
$downloadQueue = array();
$skipDownload = false;

function consoleLog($message) {
  echo $message."\n";
}

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

function setupCURL(&$ch) {
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1");
}

function getData(&$html, &$json, &$dirName) {
  //Getting images
  consoleLog("Getting JSON data...");
  $posStart = strpos($html, "var images = ");
  $posEnd = strpos($html, "};", $posStart);
  $json = substr($html, $posStart+13, $posEnd-$posStart-12);

  //Getting title
  consoleLog("Getting name of chapter...");
  $posNameStart = strpos($html, 'selected="true"');
  $posNameEnd = strpos($html, "<", $posNameStart);
  $dirName = substr($html, $posNameStart+16, $posNameEnd-$posNameStart-16);
}

function downloadImage(&$key, &$dirName, &$count, &$imageCount) {
  consoleLog("Downloading file ".($count+1)."/".$imageCount);
  $key = trim($key);
  $saveTo = sprintf("%03d.jpg", $count);
  $fp = fopen($dirName."/".$saveTo, 'w+');
  if($fp === false){
    throw new Exception('Could not open: ' . $dirName."/".$saveTo);
    die();
  }
  $ch = curl_init($key);
  curl_setopt($ch, CURLOPT_FILE, $fp);
  curl_setopt($ch, CURLOPT_TIMEOUT, 600);
  curl_exec($ch);
  if(curl_errno($ch)){
    throw new Exception(curl_error($ch));
    die();
  }
  curl_close($ch);
  $count++;
}

function convertToCBZ($dirName) {
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
  consoleLog("Saved as \"".$dirName.".cbz\"");
  $zip->close();
}

function removeFiles($dirName) {
  //Getting files to add
  $fileTable = scandir($dirName);
  consoleLog("Cleaning up...");
  for ($i=2;$i<count($fileTable);$i++) {
    unlink($dirName."/".$fileTable[$i]);
  }
  rmdir($dirName);
}

function getNextIdLine(&$html) {
  $chapterIdStart = strpos($html, 'var nextCha =');
  $chapterIdEnd = strpos($html, ';', $chapterIdStart);
  return substr($html, $chapterIdStart, $chapterIdEnd-$chapterIdStart);
}

function getUniqueId($chapterId) {
  $chapterId = substr($chapterId, 14);
  $chapterId = json_decode($chapterId);
  return $chapterId->base->uniqueId;
}

function downloadChapter($url) {
  global $keepFiles, $tillLastChapter, $convert, $skipDownload;
  //Downloaing page
  $json = $dirName = "";
  $ch = curl_init($url);
  setupCURL($ch);
  consoleLog("Downloading webpage $url");
  $html = curl_exec($ch);
  if(curl_errno($ch)){
      throw new Exception(curl_error($ch));
      die();
  }
  $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  //Getting images URLs
  if ($html !== false) {
    //Decoding JSON and making directory
    getData($html, $json, $dirName);
    $file = json_decode($json, true);
    $count = 0;
    $imageCount = count($file);
    if (!is_dir($dirName)) {
      mkdir($dirName);
    }
    if (!$skipDownload) {
      //Loop for downloading images
      consoleLog("Downloading images for \"$dirName\"");
      foreach ($file as $key) {
        downloadImage($key, $dirName, $count, $imageCount);
      }
    }
    //Creating archive
    if ($convert) {
      convertToCBZ($dirName);
    } else {
      consoleLog("Skipping conversion to .cbz archive");
    }
    if (!$keepFiles) {
      removeFiles($dirName);
    }
    consoleLog("Done");
    if ($tillLastChapter) {
      $chapterLine = getNextIdLine($html);
      if (strpos($chapterLine, "= null") === false) {
        downloadChapter("https://bato.to/chapter/".getUniqueId($chapterLine));
      } else {
        consoleLog("Reached last chapter, stopping program.");
      }
    }
  }
}

//Main program

setupProgram();
foreach ($downloadQueue as $downloadItem) {
  downloadChapter($downloadItem);
}




 ?>
