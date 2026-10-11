#!/usr/bin/env php
<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

const FIELD_GUIDE_URL = 'https://afieldguidetoelephpants.net/data/all.json';
const ELEPHPANT_ME_URL = 'https://www.elephpant.me/api/elephpants';

function fetchAllElephpantMe() : array {
	$all = [];
	$url = ELEPHPANT_ME_URL;

	while($url !== null) {
		$page = fetchJson($url);

		foreach($page->data as $entry) {
			$all[] = $entry;
		}

		$url = $page->links->next;
	}

	return $all;
}

function fetchJson(string $url) : stdClass {
	$context = stream_context_create([
		'http' => [
			'header'  => "User-Agent: elephpant-correlation-bot\r\n",
			'timeout' => 30,
		],
	]);

	$text = file_get_contents($url, false, $context);

	if($text === false) {
		throw new RuntimeException("Unable to fetch $url");
	}

	/** @noinspection PhpUnhandledExceptionInspection */
	return json_decode($text, false, 512, JSON_THROW_ON_ERROR);
}

function formatFieldGuide(string $species, ?stdClass $entry) : string {
	if($entry === null) {
		return sprintf('`%s`', $species);
	}

	return sprintf('`%s` %s %s', $species, $entry->variation ?? '', $entry->sponsor ?? '');
}

function formatElephpantMe(stdClass $entry) : string {
	$name = $entry->name ?? '';
	if(!empty($entry->format) && $entry->format == 'large') {
		$name = ucfirst($entry->format) . " $name";
	}
	return sprintf('`%s` %s %s %s', $entry->id, $name, $entry->sponsor ?? '', $entry->description ?? '');
}

function escapePipe(string $value) : string {
	return str_replace('|', '\|', $value);
}

$fieldGuide = fetchJson(FIELD_GUIDE_URL);

$elephpantMe = fetchAllElephpantMe();

/** @noinspection PhpUnhandledExceptionInspection */
$correlations = json_decode(file_get_contents(__DIR__ . '/correlations.json'), false, 512, JSON_THROW_ON_ERROR);

$meById = [];

foreach($elephpantMe as $entry) {
	$meById[(int)$entry->id] = $entry;
}

$matchedFieldGuide = [];
$matchedElephpantMe = [];

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$readme = ['# elePHPant Species Correlation'];
$readme[] = '';
$readme[] = 'This repository correlates species between [**A Field Guide to elePHPants**]' .
		'(https://github.com/philipsharp/afieldguidetoelephpants) and [**elephpant.me**]' .
		'(https://github.com/jgrossi/elephpant.me)';
$readme[] = '';

$readme[] = '';
$readme[] = '| A Field Guide to elePHPants | elephpant.me |';
$readme[] = '| --- | --- |';

foreach($correlations->correlations ?? [] as $fieldGuideId => $elephpantMeIds) {
	$matchedFieldGuide[$fieldGuideId] = true;

	$right = [];

	foreach($elephpantMeIds as $elephpantMeId) {
		$id = (int)$elephpantMeId;
		if(isset($meById[$elephpantMeId])) {
			$matchedElephpantMe[$id] = true;
			$right[] = formatElephpantMe($meById[$id]);
		} else {
			throw new LogicException("Invalid id $elephpantMeId on $fieldGuideId");
		}
	}

	$fieldGuideText = formatFieldGuide($fieldGuideId, $fieldGuide->$fieldGuideId ?? null);
	$readme[] = sprintf('| %s | %s |', escapePipe($fieldGuideText), escapePipe(implode('<br>', $right)));
}

foreach($correlations->fieldguide_only ?? [] as $species => $object) {
	$matchedFieldGuide[$species] = true;

	$reason = $object->reason ?? '(intentionally unpaired)';

	$readme[] = sprintf(
			'| %s | *%s* |',
			escapePipe(formatFieldGuide($species, $fieldGuide->$species ?? null)),
			escapePipe($reason),
	);
}

foreach($correlations->elephpant_me_only ?? [] as $id => $object) {
	$id = (int)$id;

	if(!isset($meById[$id])) {
		throw new LogicException("Invalid id $id");
	}

	$matchedElephpantMe[$id] = true;

	$reason = $object->reason ?? '(intentionally unpaired)';

	$readme[] = sprintf(
		'| *%s* | %s |',
		escapePipe($reason),
		escapePipe(formatElephpantMe($meById[$id])),
	);
}

$readme[] = '';

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$missingFieldGuide = array_filter($elephpantMe, fn(stdClass $e) : bool => !isset($matchedElephpantMe[(int)$e->id]));

usort(
	$missingFieldGuide,
	fn(stdClass $a, stdClass $b) : int => $a->id <=> $b->id
);

if(count($missingFieldGuide)) {
	$readme[] = '';
	$readme[] = '## Missing from A Field Guide to elePHPants';
	$readme[] = '';

	foreach($missingFieldGuide as $entry) {
		$readme[] = '* ' . formatElephpantMe($entry);
	}
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$missingElephpantMe = array_filter((array)$fieldGuide, fn($s) => !isset($matchedFieldGuide[$s]), ARRAY_FILTER_USE_KEY);

ksort($missingElephpantMe);

if(count($missingElephpantMe)) {
	$readme[] = '';
	$readme[] = '## Missing from elephpant.me';
	$readme[] = '';

	foreach($missingElephpantMe as $species => $entry) {
		$readme[] = '* ' . formatFieldGuide($species, $entry);
	}
	$readme[] = '';
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$missingPhotos = array_filter((array)$fieldGuide, fn(stdClass $e) : bool => !isset($e->photos));

if(count($missingPhotos)) {
	$readme[] = '';
	$readme[] = '## Photos missing from A Field Guide to elePHPants';
	$readme[] = '';

	foreach($missingPhotos as $species => $entry) {
		$readme[] = '* ' . formatFieldGuide($species, $entry);
	}
	$readme[] = '';
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$readme[] = '## Large and Small correlation';
$readme[] = '';

$readme[] = '| Large | Small |';
$readme[] = '| ----- | ----- |';
foreach($correlations->small_and_large ?? [] as $small => $large) {
	if($large === false) {
		$readme[] = '| none | ' . formatElephpantMe($meById[$small]) . ' |';
	} elseif($large === null) {
		$readme[] = '| _unknown_ | ' . formatElephpantMe($meById[$small]) . ' |';
	} else {
		$readme[] = '| ' . formatElephpantMe($meById[$large] ?? new stdClass()) . ' | ' . formatElephpantMe($meById[$small]) . ' |';
	}
}
$readme[] = '';

$mappedLargeAndSmall = [
		...(array)$correlations->small_and_large ?? [],
		...array_keys((array)$correlations->small_and_large ?? []),
];
if(count($mappedLargeAndSmall) < count($meById)) {
	$readme[] = '';
	$readme[] = 'Unknown:';
	foreach($meById as $id => $elephpant) {
		if(in_array($id, $mappedLargeAndSmall)) {
			continue;
		}
		$readme[] = '* ' . formatElephpantMe($elephpant);
	}
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$unaccounted = [];
foreach($elephpantMe as $entry) {
	if($entry->format != 'large') {
		continue;
	}
	if(!in_array((int)$entry->id, get_object_vars($correlations->small_and_large ?? new stdClass()), true)) {
		$unaccounted[] = ' * ' . formatElephpantMe($entry);
	}
}
if(count($unaccounted)) {
	$readme[] = '## Larges unaccounted for';
	$readme = [...$readme, '', ...$unaccounted, ''];
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$readme[] = '';

file_put_contents(__DIR__ . '/README.md', implode("\n", $readme));
