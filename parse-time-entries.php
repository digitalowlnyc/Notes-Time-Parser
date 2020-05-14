<?php
/**
 * Creator: Bryan Mayor
 * Company: Blue Nest Digital, LLC
 * License: (Blue Nest Digital LLC, All rights reserved)
 * Copyright: Copyright 2020 Blue Nest Digital LLC
 */

define("SLOW_SCRIPT_START", false);

$regexMap = [
	"date" => [
		"#(\d{1,2}/\d{1,2}/\d{2,4})#"
	],
	"time" => [
		"#(1\+)?(\d{1,2}:\d{2})[H|h]{0,1}( (.+))?#"
	]
];

function matchRegex($regex, $subject) {
	$matched = preg_match($regex, $subject, $results);

	if(!$matched) {
		return null;
	}

	return $results;
}

$parsedEntries = [];

$target = $argv[1];

$USING_NOTES = false;
if($target === "notes") {
	$USING_NOTES = true;

	echo "Using actual notes as input" . PHP_EOL;
	$notes = file_get_contents("/tmp/notes.out");
	$entries = explode("[END NOTE]", $notes);
} else if(is_dir($target)) {
	$notes = glob($target . "/" . "*.note");
	$entries = $notes;
} else if(is_file($target)) {
	$entries = [
		$target
	];
} else {
	die("Could not find specified file or directory");
}

function getLines($filename) {
	$contents = file_get_contents($filename);

	if($contents === false) {
		echo "Could not open file: " . $filename . PHP_EOL;
		die();
	}

	return explode(PHP_EOL, $contents);
}

function convertHtmlToText($str) {
	$tempNoteFile = "/tmp/temp_note.html";

	if(!file_put_contents($tempNoteFile, $str)) {
		die("Could not write file: " . $tempNoteFile);
	}

	$command = "textutil  -convert txt " . $tempNoteFile . " -stdout";
	//echo $command . PHP_EOL;
	$output = shell_exec($command);
	return $output;
}

function testLineRegexes($line) {
	global $regexMap;

	foreach($regexMap as $label => $regexes) {
		foreach($regexes as $regex) {
			$results = matchRegex($regex, $line);

			if($results !== null) {
				$matchedLabel = $label;
				return [$matchedLabel, $results];
			}
		}
	}

	return null;
}

if(SLOW_SCRIPT_START) {
	echo "Will process: " . PHP_EOL;
	echo json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
	sleep(10);
}

function getNoteContents($noteData) {
	preg_match_all("#name:(.+)\n{1}?body:(.+)#s", $noteData, $matches, PREG_SET_ORDER);

	if(count($matches) === 0) {
		echo $noteData . PHP_EOL;
		echo "No matches" . PHP_EOL;
		die();
	}

	if(count($matches) > 1) {
		die("Too many matches");
	}

	$match = $matches[0];

	$title = $match[1];
	$body = $match[2];

	$body = convertHtmlToText($body);

	return [
		"title" => $title,
		"body" => $body
	];
}

foreach($entries as $entryIdx => $entry) {
	echo "=========Processing=======: " . $entryIdx . "/" . count($entries) . "========" . PHP_EOL;

	if($USING_NOTES) {
		$entry = getNoteContents($entry);
		$body = $entry["body"];
		$lines = explode(PHP_EOL, $body);
	} else {
		$body = $entry;
		$lines = getLines($body);
	}

	$lastEntry = null;

	$startTime = null;
	$endTime = null;
	$endDate = null;
	$description = null;

	$currentDate = null;

	foreach($lines as $i=>$line) {

		$matchedLabel = null;
		$results = null;

		$return = testLineRegexes($line);

		if($return !== null) {
			$matchedLabel = $return[0];
			$results = $return[1];
		}

		if($matchedLabel === "date") {
			if($i !== 0) {
				throw new RuntimeException("Found date but not on first line (line " . $i . ")");
			}

			$currentDate = DateTimeImmutable::createFromFormat("m/d/Y", $results[0]);

			if($currentDate === false) {
				die("Could not parse date");
			}

		} else if($matchedLabel === "time") {
			if($startTime === null) {
				$startTime = $results[2];
				$description = trim($results[3]);
			} else {
				$endTime = $results[2];

				if($results[1] === "1+") {
					$endDate = $currentDate->modify('+1 day');
				} else {
					$endDate = $currentDate;
				}
			}
		} else if(trim($line) !== "") {
			$matchedLabel = "empty";
		} else {
			if($lastEntry === "time" && $startTime !== null) {
				throw new RuntimeException("Expecting a time: " . $line);
			}
			$matchedLabel = null;
		}
		echo $i . ": " . $line . " --> matched as: " . $matchedLabel . PHP_EOL;

		if($endTime !== null) {
			if($currentDate === null) {
				throw new RuntimeException("Did not find date");
			}

			$currentEntry = [
				"date" => $currentDate->format("m/d/Y"),
				"start_time" => $startTime,
				"end_time" => $endTime,
				"end_date" => $endDate->format("m/d/Y"),
				"description" => $description,
				"is_spans_days" => $currentDate !== $endDate
			];

			echo "Recorded entry" . PHP_EOL;

			$parsedEntries[] = $currentEntry;
			$startTime = null;
			$endTime = null;
			$endDate = null;
			$description = null;
		}

		$lastEntry = $matchedLabel;
	}
}

print_r($parsedEntries);