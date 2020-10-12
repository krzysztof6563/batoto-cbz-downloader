<?php
 /*
 * Author: Krzysztof Michalski
 * Gitlab URL: https://gitlab.com/krzysztof6563/
 *
 * An application that downloads chapters of manga from bato.to.
 * It has an ability to download untill the end of current series.
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

function stop() {
  consoleLog("Stopping the script");
  die();
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
          stop();
      }
    } else {
      array_push($downloadQueue, $argv[$i]);
    }
  }
}

//Extracts data (iamges, title) from HTML
function getData(&$html, &$json, &$dirName) {
  //Getting images
  consoleLog("Getting JSON data...");
  $matches = [];
  $result = preg_match('/images = [\w\d\s{}\[\]":\/\-\.,]{0,}/', $html, $matches);
  if ($result == FALSE || $result == 0) {
    consoleLog("Could not find JSON data for images");
    stop();
  }
  $json = substr($matches[0], strpos($matches[0], "["));

  //Getting title
  consoleLog("Getting name of chapter...");
  $result = preg_match('/title>[^<\/]{0,}/', $html, $matches);
  if ($result == FALSE || $result == 0) {
    consoleLog("Could not find name of chapter in HTML.");
    stop();
  }
  $dirName = substr($matches[0], strpos($matches[0], ">")+1);
}

//Downloads image, creates filename
function downloadImage(&$key, &$dirName, &$count, &$imageCount) {
  consoleLog("Downloading file ".($count+1)."/".$imageCount);
  $imageURL = trim("https:".$key);
  $saveTo = sprintf("%03d.jpg", $count);
  file_put_contents($dirName."/".$saveTo, file_get_contents($imageURL));
  $count++;
}

//Adds files to CBZ archive and outputs it to $dirName.cbz
function convertToCBZ($dirName) {
  consoleLog("Creating archive...");
  $zip = new ZipArchive();
  $result = $zip->open($dirName.".cbz", ZipArchive::CREATE | ZipArchive::OVERWRITE);
  if ($result !== true) {
    consoleLog("Can not open zip file: $result");
    stop();
  }
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
function checkURLForSeries(&$url) {
  if (strpos($url, "/series/")) {
    return true;
  }
  return false;
}

//Checks if given URL is linking to chapter
function checkURLForChapter(&$url) {
  if (strpos($url, "/chapter/")) {
    return true;
  }
  return false;
}

function isBatotoURL(&$url) {
  $res = preg_match('/bato.to\//', $url);
  if ($res === 1) {
    return true;
  } else {
    return false;
  }
}

//Downloads webpage to $html
function downloadWebpage($url) {
  if (!isBatotoURL($url)) {
    consoleLog("Given URL is not linking to bato.to service.");
    stop();
  }
  consoleLog("Downloading webpage $url");
  $html = file_get_contents($url);
  return $html;
}

function getChapterURL($id) {
  return "https://bato.to/chapter/".$id;
}

//Returns URL to first chapter of series
function getFirstChapterURL($html) {
  $url = substr($html, strrpos($html, '<a class="chapt" href="/chapter/'));
  $matches = [];
  preg_match('/\/chapter\/[0-9]{1,}/', $url, $matches);
  $id = substr($matches[0], strrpos($matches[0], "/")+1);
  return getChapterURL($id);
}

//Downloads chapter at given URL
function downloadChapter($url, &$options) {
  //Downloaing page
  $json = $dirName = $html = "";
  $html = downloadWebpage($url);
  //if given URL is a link to series, then extract first chapter webpage
  if (checkURLForSeries($url)) {
    consoleLog("Given URL is a link to series page, downloading first chapter instead.");
    $url = getFirstChapterURL($html);
    $html = downloadWebpage($url);
  }
  if (!checkURLForChapter($url)) {
	consoleLog("Given URL does not link to chapter.");
	stop();
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
      if(!mkdir($dirName)){
        consoleLog("Can not create directory: $dirName");
        stop();
      }
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
        consoleLog("Reached last chapter, stopping the script.");
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
