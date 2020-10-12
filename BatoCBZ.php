#!/bin/env php
<?php
 /*
 * Author: Krzysztof Michalski
 * GitHub URL: https://github.com/krzysztof6563
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

/**
 * Echoes string with new line
 * 
 * @param string $message Message to echo
 */
function consoleLog($message) {
  echo $message.PHP_EOL;
}

/**
 * Sets up defaults options for program
 * 
 * @param array &$option Array of options to set up
 */
function setDeafults(&$options) {
  $options["untillLastChapter"] = false;
  $options["keepFiles"] = false;
  $options["convert"] = true;
  $options["skipDownload"] = false;
}

/**
 * Constructs DOMDocument object and loads $html to it 
 * 
 * @param string $html HTML string
 * @return DOMDocument With loaded $html
 */
function getDOMDocument($html) {
  libxml_use_internal_errors(false);
  $document = new DOMDocument();
  @$document->loadHTML($html);
  return $document;
}

/**
 * Dispatch passed arguments (options and URLs)
 * 
 * @param array &$options Array of options to overwrite
 * @param array &$downloadQueue Array of URLs to download
 */
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
          throw new Exception("Unknown option: $argv[$i]");
      }
    } else {
      array_push($downloadQueue, $argv[$i]);
    }
  }
}

/**
 * Extract name of chapter and urls of images from HTML document
 * 
 * @param string $html
 * @param string &$json Variable to decode json string to
 * @param string &$dirName Variable to save directory name to
 */
function getData($html, &$json, &$dirName) {
  $document = getDOMDocument($html);
  //Getting images
  $matches = [];
  $result = preg_match('/images = [\w\d\s{}\[\]":\/\-\.,]{0,}/', $html, $matches);
  if ($result == FALSE || $result == 0) {
    throw new Exception("Could not find JSON data for images");
  }
  $jsonString = substr($matches[0], strpos($matches[0], "["));
  $json = json_decode($jsonString, true);
  
  //Getting title
  $title = $document->getElementsByTagName("title")[0]->textContent;
  $title = explode(' - ', $title);
  array_pop($title);
  $dirName = implode(' - ', $title);

  consoleLog("Name of chapter: $dirName");
  consoleLog("Found ".count($json)." images");
}

/**
 * Prepare filename and download iamge from $url
 * 
 * @param string $url URL of image, without protocol
 * @param string $dirName Name of directory to put file in
 * @param string &$count Index of current image from chapter
 * @param string $imageCount Number of all images from chapter
 */
function downloadImage($url, $dirName, &$count, $imageCount) {
  consoleLog("Downloading file ".($count+1)."/".$imageCount);
  $imageURL = trim("https:".$url);
  $saveTo = sprintf("%03d.jpg", $count);
  $currentTry = 1;
  $file = false;

  while (false === $file && $currentTry <= 5) {
      $file = @file_get_contents($imageURL);
      if ($file === false) {
        $time = $currentTry * 5;
        consoleLog("There was a problem downloading $imageURL. Waiting $time seconds and retrying.");
        sleep($time);
        $currentTry++;
      }
  }

  if (false !== $file) {
    file_put_contents($dirName."/".$saveTo, $file);
  } else {
    consoleLog("!!! Image at $imageURL was not downloaded. !!!");
  }

  $count++;
}

/**
 * Adds files to CBZ archive and outputs it to $dirName.cbz
 * 
 * @param string $dirName Directory to pack
 */
function convertToCBZ($dirName) {
  consoleLog("Creating archive...");
  $zip = new ZipArchive();
  $result = $zip->open($dirName.".cbz", ZipArchive::CREATE | ZipArchive::OVERWRITE);
  if ($result !== true) {
    throw new Exception("Can not open zip file: $result");
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

/**
 * Cleans up downloaded files, skippable with --keep-files
 * 
 * @param string $dirName Directory to remove
 */
function removeFiles($dirName) {
  //Getting files to add
  $fileTable = scandir($dirName);
  consoleLog("Cleaning up...");
  for ($i=2;$i<count($fileTable);$i++) {
    unlink($dirName."/".$fileTable[$i]);
  }
  rmdir($dirName);
}

/**
 * Extract next chapter ID from HTML string
 * 
 * @param string  $html HTML document
 * @return string|null Chapter ID
 */
function getNextChapterID(string $html) :?string {
  $document = getDOMDocument($html);
  $finder = new DOMXPath($document);

  $nextChapterElement = $finder->query('//div[contains(@class, "nav-next")]/a')[0];
  $urlParts = explode("/", $nextChapterElement->getAttribute('href'));

  if ($urlParts[1] === "series") {
    return null;
  }

  return end($urlParts);
}

/**
 * Checks if given URL is linking to series
 * 
 * @param string $url
 * @return bool 
 */
function checkURLForSeries($url) :bool {
  return strpos($url, "/series/") !== false;
}

/**
 * Checks if given URL is linking to chapter
 * 
 * @param string $url URL to check
 * @return bool
 */
function checkURLForChapter($url) :bool {
  return strpos($url, "/chapter/") !== false;
}

/**
 * Check if passed $url belongs to bato.to domain
 * 
 * @param string &$url URL to check
 * @return bool 
 */
function isBatotoURL(&$url) :bool {
  return preg_match('/bato.to\//', $url) === 1;
}

/**
 * Get HTML string of webpage
 * 
 * @param string  $url URL of webpage
 * @return string HTML od webpage
 */
function downloadWebpage($url) :string {
  if (!isBatotoURL($url)) {
    throw new Exception("Given URL is not linking to bato.to service.");
  }
  consoleLog("Downloading webpage $url");
  $html = file_get_contents($url);
  return $html;
}

function getChapterURL($id) {
  return "https://bato.to/chapter/".$id;
}

/**
 * Returns URL to first chapter of series
 * 
 * @param string $html HTML document
 * @return string URL of first chapter 
 */
function getFirstChapterURL($html) :string {
  $document = getDOMDocument($html);
  $fidner = new DOMXPath($document);

  $chapters = $fidner->query('//div[@class="main"]//a[contains(@class, "chapt")]');

  $lastNode = $chapters[$chapters->length - 1];
  $urlParts = explode("/", $lastNode->getAttribute('href'));
  $id = end($urlParts);
  return getChapterURL($id);
}

/**
 * Downloads chapter at given URL
 * 
 * @param string $url URL to download manga from
 * @param array &$options Options for downloading
 */
function downloadChapter($url, &$options) {
  //Downloaing page
  $json = [];
  $dirName = $html = "";
  $html = downloadWebpage($url);
  //if given URL is a link to series, then extract first chapter webpage
  if (checkURLForSeries($url)) {
    consoleLog("Given URL is a link to series page, downloading all chapter instead.");
    
    $url = getFirstChapterURL($html);
    $options["untillLastChapter"] = true;
    $html = downloadWebpage($url);
  }
  if (!checkURLForChapter($url)) {
	  throw new Exception("Given URL does not link to chapter.");
  }
  //Getting images URLs
  if ($html !== false) {
    //Decoding JSON and making directory
    getData($html, $json, $dirName);
    //Sanitize file name
    $dirName = preg_replace( '/[^a-zA-Z0-9]+/', '_', $dirName);
    $count = 0;
    $imageCount = count($json);
    //Create directory
    if (!is_dir($dirName)) {
      if(!mkdir($dirName)){
        throw new Exception("Can not create directory: $dirName");
      }
    }
    if (!$options["skipDownload"]) {
      //Loop for downloading images
      consoleLog("Downloading images for \"$dirName\"");
      foreach ($json as $key) {
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
      $nextChapterID = getNextChapterID($html);
      if ($nextChapterID != null) {
        downloadChapter(getChapterURL($nextChapterID), $options);
      } else {
        consoleLog("Reached last chapter, stopping the script.");
      }
    }
  }
}

/**
 * Main program function
 */
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
