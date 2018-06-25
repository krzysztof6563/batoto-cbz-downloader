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
 * --no-convert, -n - prevents packing-up to .cbz archive
 * --keep-files, -k - prevents automatic deletion of downloaded files
 * --untill-last-chapter, -l - continues to download next chapters until the end of a current series
 * --skip-download, -s - skips downloading files (usefull in debugging)
*/

function consoleLog($message) {
  echo $message."\n";
}

function setDeafults(&$options) {
  $options["untillLastChapter"] = false;
  $options["keepFiles"] = false;
  $options["convert"] = true;
  $options["skipDownload"] = false;
}

//Checking passed arguments
function setupProgram(&$options, &$downloadQueue) {
  global $argc, $argv;
  for ($i=1;$i<$argc;$i++) {
    if (substr($argv[$i], 0 , 1) == "-") {
      switch ($argv[$i]) {
        case '--keep-files':
        case '-k':
          $options["keepFiles"] = true;
          break;
        case '--untill-last-chapter':
        case '-l':
          $options["untillLastChapter"] = true;
          break;
        case '--no-convert':
        case '-n':
          $options["convert"] = false;
          break;
        case '--skip-download':
        case '-s':
          $options["skipDownload"] = true;
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

//Sets up CURL for downloading webpage
function setupCURL(&$ch) {
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.1) Gecko/20061204 Firefox/2.0.0.1");
}

//Extracts data (iamges, title) from HTML
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

//Downloads image, creates filename
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

//Adds files to CBZ archive and outputs it to $dirName.cbz
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

//Cleans up downloaded files, skippable with --keep-files
function removeFiles($dirName) {
  //Getting files to add
  $fileTable = scandir($dirName);
  consoleLog("Cleaning up...");
  for ($i=2;$i<count($fileTable);$i++) {
    unlink($dirName."/".$fileTable[$i]);
  }
  rmdir($dirName);
}

//Gets line with id of next chapter
function getNextIdLine(&$html) {
  $chapterIdStart = strpos($html, 'var nextCha =');
  $chapterIdEnd = strpos($html, ';', $chapterIdStart);
  return substr($html, $chapterIdStart, $chapterIdEnd-$chapterIdStart);
}

//Gets id of next chapter
function getUniqueId($chapterId) {
  $chapterId = substr($chapterId, 14);
  $chapterId = json_decode($chapterId);
  return $chapterId->base->uniqueId;
}

//Checks if given URL is linking to series or chapter
function checkURL(&$url) {
  if (strpos($url, "/series/")) {
    return true;
  }
  return false;
}

//Downloads webpage to $html
function downloadWebpage($url) {
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
  return $html;
}

function getChapterURL($id) {
  return "https://bato.to/chapter/".$id;
}

//Returns URL to first chapter of series
function getFirstChapterURL($html) {
  $url = substr($html, strrpos($html, '<a class="chapt" href="/chapter/'));
  $matches;
  preg_match('/\/chapter\/[0-9]{1,}/', $url, $matches);
  $id = substr($matches[0], strrpos($matches[0], "/")+1);
  return getChapterURL($id);
}

function downloadChapter($url, &$options) {
  //Downloaing page
  $isSeries = checkURL($url);
  $json = $dirName = $html = "";
  $html = downloadWebpage($url);
  if ($isSeries) {
    consoleLog("Given URL is a link to series page, downloading first chapter instead.");
    $url = getFirstChapterURL($html);
    $html = downloadWebpage($url);
  }
  //Getting images URLs
  if ($html !== false) {
    //Decoding JSON and making directory
    getData($html, $json, $dirName);
    //Sanitize file name
    $dirName = preg_replace( '/[^a-zA-Z0-9]+/', '_', $dirName);
    //Change JSON to array
    $file = json_decode($json, true);
    $count = 0;
    $imageCount = count($file);
    //Create directory
    if (!is_dir($dirName)) {
      mkdir($dirName);
    }
    if (!$options["skipDownload"]) {
      //Loop for downloading images
      consoleLog("Downloading images for \"$dirName\"");
      foreach ($file as $key) {
        downloadImage($key, $dirName, $count, $imageCount);
      }
    }
    //Creating archive
    if ($options["convert"]) {
      convertToCBZ($dirName);
    } else {
      consoleLog("Skipping conversion to .cbz archive");
    }
    //Removing files
    if (!$options["keepFiles"]) {
      removeFiles($dirName);
    }
    consoleLog("Done");
    //Get next chapter if user wants to
    if ($options["untillLastChapter"]) {
      $chapterLine = getNextIdLine($html);
      if (strpos($chapterLine, "= null") === false) {
        downloadChapter(getChapterURL(getUniqueId($chapterLine)), $options);
      } else {
        consoleLog("Reached last chapter, stopping program.");
      }
    }
  }
}

//Main program startup
function main() {
  //Setting up initial values
  $options = $downloadQueue = [];
  //Sets up default and user options
  setDeafults($options);
  setupProgram($options, $downloadQueue);
  //Main download loop
  foreach ($downloadQueue as $downloadItem) {
    downloadChapter($downloadItem, $options);
  }
}

main();

 ?>
